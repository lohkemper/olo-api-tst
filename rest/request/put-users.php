<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Specialized PUT handler for users endpoint (Admin)
 * Handles: PUT /api/users/{id}
 *
 * Aktualisiert einen Benutzer (email, username, first_name, last_name, is_active;
 * optional password). Erfordert users.update.any oder admin.access.
 */
class requestPutUsers extends RequestBase {
    private array $request = [];
    private array $data = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            $this->log('requestPutUsers::execute');
            $this->log(['requestPutUsers::request', $this->request]);

            CsrfHelper::requireValidToken();
            $this->requireAnyPermission(['users.update.any', 'admin.access']);

            if (!isset($this->request['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'User ID is required']);
                return;
            }
            $userId = is_array($this->request['id']) ? (int)$this->request['id'][0] : (int)$this->request['id'];

            if (!$this->getUserRow($userId)) {
                http_response_code(404);
                echo json_encode(['error' => 'User not found']);
                return;
            }

            // camelCase-Body → snake_case-Spalten (nur erlaubte Felder)
            $map = [
                'email' => 'email',
                'username' => 'username',
                'firstName' => 'first_name',
                'lastName' => 'last_name',
                'isActive' => 'is_active',
            ];
            $sets = [];
            $params = ['id' => $userId];
            foreach ($map as $in => $col) {
                if (array_key_exists($in, $this->data)) {
                    $sets[] = "`$col` = :$col";
                    $params[$col] = $col === 'is_active'
                        ? (int)((bool)$this->data[$in])
                        : $this->data[$in];
                }
            }

            // Optionales Passwort
            if (!empty($this->data['password'])) {
                if (strlen((string)$this->data['password']) < 6) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Password must be at least 6 characters']);
                    return;
                }
                $sets[] = "`password_hash` = :password_hash";
                $params['password_hash'] = password_hash($this->data['password'], PASSWORD_BCRYPT);
            }

            if (empty($sets)) {
                http_response_code(400);
                echo json_encode(['error' => 'No updatable fields provided']);
                return;
            }

            $stmt = $this->pdo->prepare(
                "UPDATE " . PREFIX . "_users SET " . implode(', ', $sets) . " WHERE users_id = :id"
            );
            $stmt->execute($params);

            $user = $this->getUserRow($userId);
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode([[
                'id' => (int)$user['users_id'],
                'email' => $user['email'],
                'username' => $user['username'],
                'firstName' => $user['first_name'] ?? '',
                'lastName' => $user['last_name'] ?? '',
                'isActive' => (bool)($user['is_active'] ?? true),
            ]]);

        } catch (\PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                http_response_code(409);
                $field = strpos($e->getMessage(), 'email') !== false ? 'Email' : 'Username';
                echo json_encode(['error' => 'Conflict', 'message' => $field . ' already exists']);
                return;
            }
            $this->handleError('Error updating user', $e);
        } catch (\Throwable $e) {
            $this->handleError('Error updating user', $e);
        }
    }

    private function getUserRow(int $userId): array|false {
        $stmt = $this->pdo->prepare(
            "SELECT users_id, username, email, first_name, last_name, is_active
             FROM " . PREFIX . "_users WHERE users_id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
