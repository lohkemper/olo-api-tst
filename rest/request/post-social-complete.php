<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /auth/social-complete { username } — schließt eine Social-Registrierung ab.
 *
 * Erfordert ein Pending mit mode=complete (gesetzt vom Callback, Fall c).
 * Legt User (password_hash NULL) + Default-Rolle 'user' + Identity in einer
 * Transaktion an und stellt die Session aus. CSRF-pflichtig (bewusst NICHT in
 * der Exempt-Liste — die Session existiert hier bereits, das Token kommt aus
 * GET /auth/social-pending).
 */
class requestPostSocialComplete extends RequestBase {
    private const PENDING_TTL = 600; // 10 min

    private array $data = [];

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $rateLimiter = new RateLimiter($this->pdo);
            $rateLimiter->requireLimit('social-complete', 5, 300);

            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }

            $pending = $_SESSION['social_pending'] ?? null;
            $valid = is_array($pending)
                && ($pending['mode'] ?? '') === 'complete'
                && (time() - (int)($pending['ts'] ?? 0)) < self::PENDING_TTL;
            if (!$valid) {
                unset($_SESSION['social_pending']);
                http_response_code(409);
                echo json_encode(['error' => 'Conflict', 'message' => 'No pending social registration']);
                return;
            }

            // Username-Regeln identisch zu POST /auth/register.
            $username = trim((string)($this->data['username'] ?? ''));
            $error = $this->validateUsername($username);
            if ($error !== null) {
                http_response_code(400);
                echo json_encode(['error' => 'Validation failed', 'errors' => ['username' => $error]]);
                return;
            }

            $this->pdo->beginTransaction();
            try {
                $stmt = $this->pdo->prepare('
                    INSERT INTO ' . PREFIX . '_users (email, username, password_hash, first_name, last_name)
                    VALUES (:email, :username, NULL, :firstName, :lastName)
                ');
                $stmt->execute([
                    'email' => (string)$pending['email'],
                    'username' => $username,
                    'firstName' => (string)($pending['firstName'] ?? '') ?: null,
                    'lastName' => (string)($pending['lastName'] ?? '') ?: null,
                ]);
                $userId = (int)$this->pdo->lastInsertId();

                $this->assignDefaultRole($userId);
                $this->insertIdentity($userId, $pending);

                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }

            unset($_SESSION['social_pending']);
            $rateLimiter->reset('social-complete');

            $user = $this->getUserRow($userId);
            $response = JwtSession::issue($this->pdo, $user);

            Logger::info('Social registration', [
                'user_id' => $userId,
                'provider' => (string)$pending['provider'],
                'username' => $username,
            ]);

            http_response_code(201);
            echo json_encode($response);

        } catch (\PDOException $e) {
            // Race: E-Mail/Username parallel registriert → Duplicate-Key.
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                $field = strpos($e->getMessage(), 'email') !== false ? 'Email' : 'Username';
                http_response_code(409);
                echo json_encode(['error' => 'Conflict', 'message' => $field . ' already exists']);
                return;
            }
            $this->handleError('Error completing social registration', $e);
        } catch (\Throwable $e) {
            $this->handleError('Error completing social registration', $e);
        }
    }

    private function validateUsername(string $username): ?string {
        if ($username === '') {
            return 'Username is required';
        }
        if (strlen($username) < 3) {
            return 'Username must be at least 3 characters';
        }
        if (strlen($username) > 50) {
            return 'Username must not exceed 50 characters';
        }
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $username)) {
            return 'Username can only contain letters, numbers, underscores and hyphens';
        }
        return null;
    }

    /** Default-Rolle 'user' (Logik wie post-register; Fehlen der Rolle bricht nicht ab). */
    private function assignDefaultRole(int $userId): void {
        $roleCheck = $this->pdo->prepare("SELECT roles_id FROM " . PREFIX . "_roles WHERE name = 'user' LIMIT 1");
        $roleCheck->execute();
        $role = $roleCheck->fetch();
        if (!$role) {
            error_log("Warning: Default role 'user' not found in database");
            return;
        }
        $this->pdo->prepare('
            INSERT INTO ' . PREFIX . '_user_roles (user_id, role_id) VALUES (:userId, :roleId)
        ')->execute(['userId' => $userId, 'roleId' => $role['roles_id']]);
    }

    private function insertIdentity(int $userId, array $pending): void {
        $this->pdo->prepare('
            INSERT INTO ' . PREFIX . '_user_identities
                (user_id, provider, provider_user_id, email, display_name, avatar_url)
            VALUES (:userId, :provider, :pid, :email, :displayName, :avatarUrl)
        ')->execute([
            'userId' => $userId,
            'provider' => (string)$pending['provider'],
            'pid' => (string)$pending['providerUserId'],
            'email' => (string)$pending['email'],
            'displayName' => (string)($pending['displayName'] ?? '') ?: null,
            'avatarUrl' => (string)($pending['avatarUrl'] ?? '') ?: null,
        ]);
    }

    private function getUserRow(int $userId): array {
        $stmt = $this->pdo->prepare('
            SELECT users_id, email, username, first_name, last_name, is_active, theme, density, accent
            FROM ' . PREFIX . '_users
            WHERE users_id = :userId
            LIMIT 1
        ');
        $stmt->execute(['userId' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            throw new \RuntimeException('User not found after social registration');
        }
        return $user;
    }
}
