<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Auth Helper Functions
 * Provides JWT validation and user authentication
 */
class AuthHelper {
    private PDO $pdo;
    private ?array $currentUser = null;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Get JWT token from Cookie (preferred) or Authorization header (fallback)
     * Priority: Cookie > Authorization Header
     */
    public function getToken(): ?string {
        // First, try to get token from HttpOnly Cookie (preferred method)
        if (isset($_COOKIE['jwt_token']) && !empty($_COOKIE['jwt_token'])) {
            return $_COOKIE['jwt_token'];
        }

        // Fallback to Authorization header for backward compatibility
        return $this->getTokenFromHeader();
    }

    /**
     * Get JWT token from Authorization header (legacy/fallback method)
     */
    private function getTokenFromHeader(): ?string {
        $headers = getallheaders();

        // Support both "Token <jwt>" and "Bearer <jwt>" formats
        if (isset($headers['Authorization'])) {
            $authHeader = $headers['Authorization'];

            if (preg_match('/^Token\s+(.+)$/i', $authHeader, $matches)) {
                return $matches[1];
            }

            if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Decode and validate JWT token using firebase/php-jwt library
     */
    public function validateToken(string $token): ?array {
        try {
            $secretKey = $_ENV['JWT_SECRET'];

            // Decode and verify JWT
            $decoded = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($secretKey, 'HS256'));

            // Convert stdClass to array
            return (array) $decoded;
        } catch (\Firebase\JWT\ExpiredException $e) {
            // Token has expired
            return null;
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            // Token signature verification failed
            return null;
        } catch (\Throwable $e) {
            // Other JWT errors (malformed, etc.)
            return null;
        }
    }

    /**
     * Get current authenticated user from database
     */
    public function getCurrentUser(): ?array {
        if ($this->currentUser !== null) {
            return $this->currentUser;
        }

        $token = $this->getToken();
        if (!$token) {
            return null;
        }

        $payload = $this->validateToken($token);
        if (!$payload || !isset($payload['userId'])) {
            return null;
        }

        // Fetch user from database with roles and permissions
        $stmt = $this->pdo->prepare("
            SELECT
                u.users_id,
                u.email,
                u.username,
                u.first_name,
                u.last_name,
                u.is_active
            FROM " . PREFIX . "_users u
            WHERE u.users_id = :userId
            LIMIT 1
        ");

        $stmt->execute(['userId' => $payload['userId']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return null;
        }

        // Load user roles and permissions
        $user['roles'] = $this->getUserRoles((int)$user['users_id']);
        $user['permissions'] = $this->getUserPermissions((int)$user['users_id']);

        $this->currentUser = $user;
        return $this->currentUser;
    }

    /**
     * Get user roles
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

        // Load permissions for each role
        foreach ($roles as &$role) {
            $role['permissions'] = $this->getRolePermissions((int)$role['roles_id']);
        }

        return $roles;
    }

    /**
     * Get permissions for a specific role
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
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get user-specific permissions (direct permissions, not from roles)
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
        ");

        $stmt->execute(['userId' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check if user is authenticated
     */
    public function isAuthenticated(): bool {
        return $this->getCurrentUser() !== null;
    }

    /**
     * Require authentication - sends 401 if not authenticated
     */
    public function requireAuth(): array {
        $user = $this->getCurrentUser();

        if (!$user) {
            http_response_code(401);
            echo json_encode([
                'error' => 'Unauthorized',
                'message' => 'Authentication required'
            ]);
            exit;
        }

        return $user;
    }
}
