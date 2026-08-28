<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Login Handler
 * Handles: POST /api/auth/login
 */
class requestPostLogin extends RequestBase {
    private array $data = [];

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            // Rate limiting: Max 5 login attempts per 5 minutes per IP
            $rateLimiter = new RateLimiter($this->pdo);
            $rateLimiter->requireLimit('login', 5, 300);

            $this->log('requestPostLogin::execute');
            $this->log(['requestPostLogin::data', $this->data]);

            // Validate input
            if (!isset($this->data['email']) || !isset($this->data['password'])) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Bad Request',
                    'message' => 'Email and password are required'
                ]);
                return;
            }

            $email = trim($this->data['email']);
            $password = $this->data['password'];

            // Find user by email
            $stmt = $this->pdo->prepare("
                SELECT
                    users_id,
                    email,
                    username,
                    password_hash,
                    first_name,
                    last_name,
                    theme,
                    density,
                    accent
                FROM " . PREFIX . "_users
                WHERE email = :email
                LIMIT 1
            ");

            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                Logger::logSecurityEvent('Failed login attempt - user not found', ['email' => $email]);
                http_response_code(401);
                echo json_encode([
                    'error' => 'Unauthorized',
                    'message' => 'Invalid email or password'
                ]);
                return;
            }

            // Verify password. Social-only-Konten haben password_hash NULL —
            // ohne Guard würde password_verify() unter strict_types mit 500 abbrechen.
            if (empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
                Logger::logSecurityEvent('Failed login attempt - invalid password', ['email' => $email]);
                http_response_code(401);
                echo json_encode([
                    'error' => 'Unauthorized',
                    'message' => 'Invalid email or password'
                ]);
                return;
            }

            // MFA-Challenge: hat der User bestätigte Methoden und kein gültiges
            // Trusted-Device-Cookie, wird KEIN JWT ausgestellt — stattdessen
            // Pending-Session + mfaRequired-Antwort (Plan: docs/planning/mfa-2fa.md).
            // Ohne MFA bleibt der Bestandspfad byte-identisch.
            if (class_exists('MfaHelper')) {
                $mfaMethods = MfaHelper::confirmedMethods($this->pdo, (int)$user['users_id']);
                if ($mfaMethods && !MfaHelper::isTrustedDevice($this->pdo, (int)$user['users_id'])) {
                    MfaHelper::beginChallenge($this->pdo, (int)$user['users_id'], $mfaMethods, 'login');
                    // Passwort-Stufe bestanden — mfa-verify hat ein eigenes Limit.
                    $rateLimiter->reset('login');
                    Logger::info('MFA challenge started', ['user_id' => $user['users_id'], 'origin' => 'login']);
                    http_response_code(200);
                    header('Content-Type: application/json');
                    echo json_encode([
                        'mfaRequired' => true,
                        'methods' => $mfaMethods,
                        'backupCodesAvailable' => MfaHelper::backupCodesRemaining($this->pdo, (int)$user['users_id']) > 0,
                        'csrfToken' => CsrfHelper::generateToken(),
                    ]);
                    return;
                }
            }

            // Update last_login timestamp
            $updateStmt = $this->pdo->prepare("
                UPDATE " . PREFIX . "_users
                SET last_login = NOW()
                WHERE users_id = :id
            ");
            $updateStmt->execute(['id' => $user['users_id']]);

            // Reset rate limit on successful login
            $rateLimiter->reset('login');

            // Session ausstellen (JWT-Cookie + CSRF) und Login-Antwort bauen —
            // zentral in JwtSession, gemeinsam mit Social-Login und MFA-Verify.
            $response = JwtSession::issue($this->pdo, $user);

            // Log successful login
            Logger::info('Successful login', [
                'user_id' => $user['users_id'],
                'email' => $email,
                'username' => $user['username']
            ]);

            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode($response);

        } catch (\Throwable $e) {
            $this->handleError('Error during login', $e);
        }
    }

}
