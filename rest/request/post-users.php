<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Specialized POST handler for users endpoint (Admin)
 * Handles: POST /api/users
 *
 * Legt einen neuen Benutzer an (Admin-Funktion, KEIN Login/JWT).
 * Erfordert users.create oder admin.access.
 */
class requestPostUsers extends RequestBase {
    private array $data = [];

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            $this->log('requestPostUsers::execute');
            $this->log(['requestPostUsers::data', $this->data]);

            $this->requireAnyPermission(['users.create', 'admin.access']);

            $errors = $this->validateInput();
            if (!empty($errors)) {
                http_response_code(400);
                echo json_encode(['error' => 'Validation failed', 'errors' => $errors]);
                return;
            }

            $email = trim($this->data['email']);
            $username = trim($this->data['username']);
            $password = $this->data['password'];
            $firstName = isset($this->data['firstName']) ? trim((string)$this->data['firstName']) : null;
            $lastName = isset($this->data['lastName']) ? trim((string)$this->data['lastName']) : null;
            $isActive = array_key_exists('isActive', $this->data) ? (int)((bool)$this->data['isActive']) : 1;

            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

            $stmt = $this->pdo->prepare("
                INSERT INTO " . PREFIX . "_users (email, username, password_hash, first_name, last_name, is_active)
                VALUES (:email, :username, :password_hash, :first_name, :last_name, :is_active)
            ");
            $stmt->execute([
                'email' => $email,
                'username' => $username,
                'password_hash' => $hashedPassword,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'is_active' => $isActive,
            ]);

            $userId = (int)$this->pdo->lastInsertId();
            $this->assignDefaultRole($userId);

            $user = $this->getUserRow($userId);

            http_response_code(201);
            header('Content-Type: application/json');
            echo json_encode([[
                'id' => (int)$user['users_id'],
                'email' => $user['email'],
                'username' => $user['username'],
                'firstName' => $user['first_name'] ?? '',
                'lastName' => $user['last_name'] ?? '',
                'isActive' => (bool)($user['is_active'] ?? true),
                'roles' => [],
                'permissions' => [],
            ]]);

        } catch (\PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                http_response_code(409);
                $field = strpos($e->getMessage(), 'email') !== false ? 'Email' : 'Username';
                echo json_encode(['error' => 'Conflict', 'message' => $field . ' already exists']);
                return;
            }
            $this->handleError('Error creating user', $e);
        } catch (\Throwable $e) {
            $this->handleError('Error creating user', $e);
        }
    }

    private function validateInput(): array {
        $errors = [];
        if (!isset($this->data['email']) || empty(trim((string)$this->data['email']))) {
            $errors['email'] = 'Email is required';
        } elseif (!filter_var($this->data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email format';
        }
        if (!isset($this->data['username']) || empty(trim((string)$this->data['username']))) {
            $errors['username'] = 'Username is required';
        } elseif (strlen((string)$this->data['username']) < 3) {
            $errors['username'] = 'Username must be at least 3 characters';
        }
        if (!isset($this->data['password']) || empty($this->data['password'])) {
            $errors['password'] = 'Password is required';
        } elseif (strlen((string)$this->data['password']) < 6) {
            $errors['password'] = 'Password must be at least 6 characters';
        }
        return $errors;
    }

    private function assignDefaultRole(int $userId): void {
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO " . PREFIX . "_user_roles (user_id, role_id)
             SELECT :uid, roles_id FROM " . PREFIX . "_roles WHERE name = 'user' LIMIT 1"
        );
        $stmt->execute(['uid' => $userId]);
    }

    private function getUserRow(int $userId): array {
        $stmt = $this->pdo->prepare(
            "SELECT users_id, username, email, first_name, last_name, is_active
             FROM " . PREFIX . "_users WHERE users_id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
