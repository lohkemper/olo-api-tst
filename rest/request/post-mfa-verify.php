<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /auth/mfa-verify { method, code?, assertion?, rememberDevice } —
 * der zentrale zweite Faktor. Erfordert eine gültige Challenge-Session
 * (mfa_pending, gesetzt von post-login bzw. get-social-callback).
 *
 * Methoden-Dispatch ist steckbar: totp | backup | email | webauthn.
 * Erfolg → Pending löschen, last_login, optional Trust-Cookie (30 Tage),
 * JwtSession::issue() → Antwort identisch zu POST /auth/login.
 * CSRF zentral erzwungen (Token aus Login-Antwort bzw. GET /auth/mfa-pending).
 */
class requestPostMfaVerify extends RequestBase {
    private array $data = [];

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $rateLimiter = new RateLimiter($this->pdo);
            $rateLimiter->requireLimit('mfa-verify', 5, 300);

            $pending = MfaHelper::pending();
            if ($pending === null) {
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized', 'code' => 'mfa_expired',
                    'message' => 'MFA challenge expired. Please sign in again.']);
                return;
            }

            $userId = (int)$pending['userId'];

            if ((int)$pending['attempts'] >= MfaHelper::MAX_ATTEMPTS) {
                MfaHelper::clearPending();
                Logger::logSecurityEvent('MFA challenge locked - too many attempts', ['user_id' => $userId]);
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized', 'code' => 'mfa_locked',
                    'message' => 'Too many attempts. Please sign in again.']);
                return;
            }

            $method = (string)($this->data['method'] ?? '');
            $verified = $this->dispatchVerify($method, $userId, $pending);

            if (!$verified) {
                $_SESSION['mfa_pending']['attempts'] = (int)$pending['attempts'] + 1;
                $remaining = MfaHelper::MAX_ATTEMPTS - $_SESSION['mfa_pending']['attempts'];
                Logger::logSecurityEvent('MFA verify failed', ['user_id' => $userId, 'method' => $method]);
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized', 'code' => 'mfa_invalid_code',
                    'message' => 'Invalid code', 'attemptsRemaining' => max(0, $remaining)]);
                return;
            }

            // Erfolg: Challenge abschließen und volle Session ausstellen.
            MfaHelper::clearPending();
            $rateLimiter->reset('mfa-verify');

            $user = MfaHelper::loadUserRow($this->pdo, $userId);
            if (!$user || !(int)($user['is_active'] ?? 1)) {
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized', 'message' => 'Account is not available']);
                return;
            }

            $this->pdo->prepare('UPDATE ' . PREFIX . '_users SET last_login = NOW() WHERE users_id = ?')
                ->execute([$userId]);
            if ($method !== 'backup') {
                MfaHelper::touchMethod($this->pdo, $userId, $method);
            }
            if (!empty($this->data['rememberDevice'])) {
                MfaHelper::issueTrustCookie($this->pdo, $userId);
            }

            $response = JwtSession::issue($this->pdo, $user);
            if ($method === 'backup') {
                $response['backupCodesRemaining'] = MfaHelper::backupCodesRemaining($this->pdo, $userId);
            }

            Logger::info('MFA verify success', [
                'user_id' => $userId,
                'method' => $method,
                'trusted' => !empty($this->data['rememberDevice']),
            ]);
            http_response_code(200);
            echo json_encode($response);
        } catch (\Throwable $e) {
            $this->handleError('Error verifying MFA challenge', $e);
        }
    }

    /**
     * Steckbarer Methoden-Dispatch. email/webauthn sind class_exists-geschützt,
     * damit Teil-Deploys ohne die jeweiligen Libs sauber mit "Invalid code"
     * statt 500 antworten.
     */
    private function dispatchVerify(string $method, int $userId, array $pending): bool {
        $code = (string)($this->data['code'] ?? '');

        switch ($method) {
            case 'totp':
                return $this->verifyTotp($userId, $code);

            case 'backup':
                return $code !== '' && MfaHelper::consumeBackupCode($this->pdo, $userId, $code);

            case 'email':
                return class_exists('MfaEmailCode')
                    && $code !== ''
                    && MfaEmailCode::verify($this->pdo, $userId, 'login', $code);

            case 'webauthn':
                $assertion = $this->data['assertion'] ?? null;
                return class_exists('WebAuthnHelper')
                    && is_array($assertion)
                    && WebAuthnHelper::verifyAssertion($this->pdo, $userId, $assertion);
        }
        return false;
    }

    private function verifyTotp(int $userId, string $code): bool {
        if ($code === '') {
            return false;
        }
        $stmt = $this->pdo->prepare('
            SELECT t.secret_encrypted, t.last_time_step
            FROM ' . PREFIX . '_user_totp t
            INNER JOIN ' . PREFIX . '_user_mfa_methods m
              ON m.user_id = t.user_id AND m.method = \'totp\' AND m.is_confirmed = 1
            WHERE t.user_id = :userId
            LIMIT 1
        ');
        $stmt->execute(['userId' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $secret = CryptoHelper::decrypt((string)$row['secret_encrypted']);
        $step = Totp::verify($secret, $code, $row['last_time_step'] !== null ? (int)$row['last_time_step'] : null);
        if ($step === null) {
            return false;
        }
        $this->pdo->prepare('UPDATE ' . PREFIX . '_user_totp SET last_time_step = ? WHERE user_id = ?')
            ->execute([$step, $userId]);
        return true;
    }
}
