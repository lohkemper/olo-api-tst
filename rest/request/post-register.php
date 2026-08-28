<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Register Handler
 * Handles: POST /api/auth/register
 */
class requestPostRegister extends RequestBase {
    private array $data = [];

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            $this->log('requestPostRegister::execute');
            $this->log(['requestPostRegister::data', $this->data]);

            // Validate input
            $errors = $this->validateInput();
            if (!empty($errors)) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Validation failed',
                    'errors' => $errors
                ]);
                return;
            }

            $email = trim($this->data['email']);
            $username = trim($this->data['username']);
            $password = $this->data['password'];

            // Check if email already exists
            if ($this->emailExists($email)) {
                http_response_code(409);
                echo json_encode([
                    'error' => 'Conflict',
                    'message' => 'Email already exists'
                ]);
                return;
            }

            // Check if username already exists
            if ($this->usernameExists($username)) {
                http_response_code(409);
                echo json_encode([
                    'error' => 'Conflict',
                    'message' => 'Username already exists'
                ]);
                return;
            }

            // Hash password
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

            // Insert user
            $stmt = $this->pdo->prepare("
                INSERT INTO " . PREFIX . "_users (email, username, password_hash)
                VALUES (:email, :username, :password_hash)
            ");

            $stmt->execute([
                'email' => $email,
                'username' => $username,
                'password_hash' => $hashedPassword
            ]);

            $userId = (int)$this->pdo->lastInsertId();

            // Assign default "user" role
            $this->assignDefaultRole($userId);

            // Fetch created user
            $user = $this->getUserById($userId);

            // Session ausstellen (JWT-Cookie + CSRF) — zentral in JwtSession,
            // Antwort im Login-Format { user, csrfToken }. Vorher stand das JWT
            // nur im Body (nie im Cookie): der Neuzugang war faktisch nicht
            // eingeloggt, jetzt ist er es.
            $response = JwtSession::issue($this->pdo, $user);

            http_response_code(201);
            header('Content-Type: application/json');
            echo json_encode($response);

        } catch (\PDOException $e) {
            // Check for duplicate entry
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                if (strpos($e->getMessage(), 'email') !== false) {
                    http_response_code(409);
                    echo json_encode(['error' => 'Conflict', 'message' => 'Email already exists']);
                    exit;
                } elseif (strpos($e->getMessage(), 'username') !== false) {
                    http_response_code(409);
                    echo json_encode(['error' => 'Conflict', 'message' => 'Username already exists']);
                    exit;
                }
            }
            $this->handleError('Error during registration', $e);
        } catch (\Throwable $e) {
            $this->handleError('Error during registration', $e);
        }
    }

    /**
     * Validate registration input
     */
    private function validateInput(): array {
        $errors = [];

        // Email
        if (!isset($this->data['email']) || empty(trim($this->data['email']))) {
            $errors['email'] = 'Email is required';
        } elseif (!filter_var($this->data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email format';
        }

        // Username
        if (!isset($this->data['username']) || empty(trim($this->data['username']))) {
            $errors['username'] = 'Username is required';
        } elseif (strlen($this->data['username']) < 3) {
            $errors['username'] = 'Username must be at least 3 characters';
        } elseif (strlen($this->data['username']) > 50) {
            $errors['username'] = 'Username must not exceed 50 characters';
        } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $this->data['username'])) {
            $errors['username'] = 'Username can only contain letters, numbers, underscores and hyphens';
        }

        // Password
        if (!isset($this->data['password']) || empty($this->data['password'])) {
            $errors['password'] = 'Password is required';
        } elseif (strlen($this->data['password']) < 6) {
            $errors['password'] = 'Password must be at least 6 characters';
        }

        return $errors;
    }

    /**
     * Check if email exists
     */
    private function emailExists(string $email): bool {
        $stmt = $this->pdo->prepare("
            SELECT users_id FROM " . PREFIX . "_users
            WHERE email = :email
            LIMIT 1
        ");

        $stmt->execute(['email' => $email]);
        return $stmt->fetch() !== false;
    }

    /**
     * Check if username exists
     */
    private function usernameExists(string $username): bool {
        $stmt = $this->pdo->prepare("
            SELECT users_id FROM " . PREFIX . "_users
            WHERE username = :username
            LIMIT 1
        ");

        $stmt->execute(['username' => $username]);
        return $stmt->fetch() !== false;
    }

    /**
     * Assign default "user" role to new user
     */
    private function assignDefaultRole(int $userId): void {
        try {
            // Check if "user" role exists
            $roleCheck = $this->pdo->prepare("SELECT roles_id FROM " . PREFIX . "_roles WHERE name = 'user' LIMIT 1");
            $roleCheck->execute();
            $role = $roleCheck->fetch();

            if (!$role) {
                // Role doesn't exist, log warning but don't fail
                error_log("Warning: Default role 'user' not found in database");
                return;
            }

            // Assign role
            $stmt = $this->pdo->prepare("
                INSERT INTO " . PREFIX . "_user_roles (user_id, role_id)
                VALUES (:userId, :roleId)
            ");

            $stmt->execute([
                'userId' => $userId,
                'roleId' => $role['roles_id']
            ]);
        } catch (\PDOException $e) {
            // Log error but don't fail registration
            error_log("Failed to assign default role: " . $e->getMessage());
        }
    }

    /**
     * Get user by ID (Rollen/Permissions lädt JwtSession selbst)
     */
    private function getUserById(int $userId): array {
        $stmt = $this->pdo->prepare("
            SELECT
                users_id,
                email,
                username,
                first_name,
                last_name,
                is_active
            FROM " . PREFIX . "_users
            WHERE users_id = :userId
            LIMIT 1
        ");

        $stmt->execute(['userId' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new \RuntimeException('User not found after registration');
        }

        return $user;
    }
}
