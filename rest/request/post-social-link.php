<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /auth/social-link { password } — verknüpft eine Social-Identität mit
 * einem bestehenden Passwort-Konto nach einmaliger Passwort-Bestätigung.
 *
 * Erfordert ein Pending mit mode=link (gesetzt vom Callback, Fall b). Bei
 * falschem Passwort bleibt das Pending bestehen (weitere Versuche bis TTL).
 * CSRF-pflichtig (Token aus GET /auth/social-pending).
 */
class requestPostSocialLink extends RequestBase {
    private const PENDING_TTL = 600; // 10 min

    private array $data = [];

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $rateLimiter = new RateLimiter($this->pdo);
            $rateLimiter->requireLimit('social-link', 5, 300);

            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }

            $pending = $_SESSION['social_pending'] ?? null;
            $valid = is_array($pending)
                && ($pending['mode'] ?? '') === 'link'
                && (time() - (int)($pending['ts'] ?? 0)) < self::PENDING_TTL;
            if (!$valid) {
                unset($_SESSION['social_pending']);
                http_response_code(409);
                echo json_encode(['error' => 'Conflict', 'message' => 'No pending account link']);
                return;
            }

            $password = (string)($this->data['password'] ?? '');
            if ($password === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Bad Request', 'message' => 'Password is required']);
                return;
            }

            $user = $this->getUserRow((int)$pending['userId']);
            if (!$user || !(int)($user['is_active'] ?? 1)) {
                unset($_SESSION['social_pending']);
                http_response_code(409);
                echo json_encode(['error' => 'Conflict', 'message' => 'Account is not available']);
                return;
            }

            if (empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
                Logger::logSecurityEvent('Failed social account link - invalid password', [
                    'user_id' => (int)$user['users_id'],
                    'provider' => (string)$pending['provider'],
                ]);
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized', 'message' => 'Invalid password']);
                return;
            }

            // INSERT IGNORE: Identity könnte durch ein Race bereits existieren.
            $this->pdo->prepare('
                INSERT IGNORE INTO ' . PREFIX . '_user_identities
                    (user_id, provider, provider_user_id, email, display_name, avatar_url)
                VALUES (:userId, :provider, :pid, :email, :displayName, :avatarUrl)
            ')->execute([
                'userId' => (int)$user['users_id'],
                'provider' => (string)$pending['provider'],
                'pid' => (string)$pending['providerUserId'],
                'email' => (string)$pending['email'],
                'displayName' => (string)($pending['displayName'] ?? '') ?: null,
                'avatarUrl' => (string)($pending['avatarUrl'] ?? '') ?: null,
            ]);

            unset($_SESSION['social_pending']);
            $rateLimiter->reset('social-link');

            $this->pdo->prepare('UPDATE ' . PREFIX . '_users SET last_login = NOW() WHERE users_id = ?')
                ->execute([(int)$user['users_id']]);

            unset($user['password_hash']);
            $response = JwtSession::issue($this->pdo, $user);

            Logger::info('Social account linked', [
                'user_id' => (int)$user['users_id'],
                'provider' => (string)$pending['provider'],
            ]);

            http_response_code(200);
            echo json_encode($response);

        } catch (\Throwable $e) {
            $this->handleError('Error linking social account', $e);
        }
    }

    private function getUserRow(int $userId): ?array {
        $stmt = $this->pdo->prepare('
            SELECT users_id, email, username, password_hash, first_name, last_name,
                   is_active, theme, density, accent
            FROM ' . PREFIX . '_users
            WHERE users_id = :userId
            LIMIT 1
        ');
        $stmt->execute(['userId' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
