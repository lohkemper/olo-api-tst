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

            // Fetch created user with roles
            $user = $this->getUserById($userId);

            // Generate JWT token
            $token = $this->generateJwtToken($user);

            // Start session for CSRF token
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            // Generate CSRF token
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            // Response
            $response = [
                'user' => [
                    'id' => (int)$user['users_id'],
                    'email' => $user['email'],
                    'username' => $user['username'],
                    'firstName' => $user['first_name'] ?? '',
                    'lastName' => $user['last_name'] ?? '',
                    'isActive' => (bool)($user['is_active'] ?? true),
                    'roles' => $user['roles'],
                    'permissions' => $user['permissions']
                ],
                'accessToken' => $token,
                'csrfToken' => $_SESSION['csrf_token']
            ];

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
     * Get user by ID with roles and permissions
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

        // Load roles with error tolerance
        try {
            $user['roles'] = $this->getUserRoles($userId);
        } catch (\PDOException $e) {
            error_log("Failed to load user roles: " . $e->getMessage());
            $user['roles'] = [];
        }

        // Load permissions with error tolerance
        try {
            $user['permissions'] = $this->getUserPermissions($userId);
        } catch (\PDOException $e) {
            error_log("Failed to load user permissions: " . $e->getMessage());
            $user['permissions'] = [];
        }

        return $user;
    }

    /**
     * Get user roles with permissions
     */
    private function getUserRoles(int $userId): array {
        $stmt = $this->pdo->prepare("
            SELECT
                r.roles_id,
                r.name,
                r.display_name,
                r.description
            FROM " . PREFIX . "_roles r
            INNER JOIN " . PREFIX . "_user_roles ur ON r.roles_id = ur.role_id
            WHERE ur.user_id = :userId
        ");

        $stmt->execute(['userId' => $userId]);
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($roles as &$role) {
            $role['roles_id'] = (int)$role['roles_id'];
            $role['permissions'] = $this->getRolePermissions((int)$role['roles_id']);
        }

        return $roles;
    }

    /**
     * Get role permissions
     */
    private function getRolePermissions(int $roleId): array {
        $stmt = $this->pdo->prepare("
            SELECT
                p.permissions_id,
                p.name,
                p.resource,
                p.action,
                p.scope,
                p.description
            FROM " . PREFIX . "_permissions p
            INNER JOIN " . PREFIX . "_role_permissions rp ON p.permissions_id = rp.permission_id
            WHERE rp.role_id = :roleId
        ");

        $stmt->execute(['roleId' => $roleId]);
        $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function($permission) {
            return [
                'id' => (int)$permission['permissions_id'],
                'name' => $permission['name'],
                'resource' => $permission['resource'],
                'action' => $permission['action'],
                'scope' => $permission['scope'],
                'description' => $permission['description'] ?? ''
            ];
        }, $permissions);
    }

    /**
     * Get user-specific permissions
     */
    private function getUserPermissions(int $userId): array {
        $stmt = $this->pdo->prepare("
            SELECT
                p.permissions_id,
                p.name,
                p.resource,
                p.action,
                p.scope,
                p.description
            FROM " . PREFIX . "_permissions p
            INNER JOIN " . PREFIX . "_user_permissions up ON p.permissions_id = up.permission_id
            WHERE up.user_id = :userId
            AND (up.expires_at IS NULL OR up.expires_at > NOW())
        ");

        $stmt->execute(['userId' => $userId]);
        $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function($permission) {
            return [
                'id' => (int)$permission['permissions_id'],
                'name' => $permission['name'],
                'resource' => $permission['resource'],
                'action' => $permission['action'],
                'scope' => $permission['scope'],
                'description' => $permission['description'] ?? ''
            ];
        }, $permissions);
    }

    /**
     * Generate JWT token
     */
    private function generateJwtToken(array $user): string {
        $header = json_encode([
            'typ' => 'JWT',
            'alg' => 'HS256'
        ]);

        $payload = json_encode([
            'userId' => $user['users_id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'iat' => time(),
            'exp' => time() + (60 * 60 * 24) // 24 hours
        ]);

        $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));

        $secretKey = $_ENV['JWT_SECRET'] ?? 'your-secret-key-change-in-production';
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secretKey, true);
        $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }
}
