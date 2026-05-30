<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Specialized GET handler for users endpoint
 * Handles: GET /api/users, GET /api/users/{id}
 *
 * Unlike the generic requestGet, this endpoint includes:
 * - User roles with their permissions
 * - Direct user permissions
 */
class requestGetUsers extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            $this->log('requestGetUsers::execute');
            $this->log(['requestGetUsers::request', $this->request]);

            // Require authentication and permission to read users
            $currentUser = $this->requireAnyPermission(['users.read', 'admin.access']);

            // Check if specific user ID is requested
            if (isset($this->request['id'])) {
                // ID can be array (from setPath) or scalar - extract first value
                $userId = is_array($this->request['id']) ? (int)$this->request['id'][0] : (int)$this->request['id'];
                $this->handleGetSingleUser($userId);
            } else {
                $this->handleGetAllUsers();
            }

        } catch (\Throwable $e) {
            $this->handleError('Error executing GET users request', $e);
        }
    }

    /**
     * GET /api/users
     * Returns all users as array with roles and permissions
     */
    private function handleGetAllUsers(): void {
        $stmt = $this->pdo->prepare("
            SELECT
                users_id,
                username,
                email,
                password_hash,
                first_name,
                last_name,
                is_active,
                created_at,
                updated_at,
                last_login
            FROM " . PREFIX . "_users
            ORDER BY username
        ");

        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Load roles and permissions for each user (camelCase, matched User-Interface)
        $formattedUsers = array_map(function($user) {
            return $this->formatUser($user);
        }, $users);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($formattedUsers);
    }

    /**
     * GET /api/users/{id}
     * Returns a single user with roles and permissions
     */
    private function handleGetSingleUser(int $userId): void {
        $stmt = $this->pdo->prepare("
            SELECT
                users_id,
                username,
                email,
                password_hash,
                first_name,
                last_name,
                is_active,
                created_at,
                updated_at,
                last_login
            FROM " . PREFIX . "_users
            WHERE users_id = :id
            LIMIT 1
        ");

        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found']);
            return;
        }

        // Return as array for consistent API responses (ISO 25010 - Kompatibilität)
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([$this->formatUser($user)]);
    }

    /**
     * User-Zeile (DB snake_case) → camelCase-Response (matched User-Interface).
     * Verschachtelte Permissions kommen als `id` (Permission-Model migriert),
     * verschachtelte Rollen behalten roles_id (Role-Model noch snake_case).
     */
    private function formatUser(array $user): array {
        $userId = (int)$user['users_id'];

        return [
            'id'           => $userId,
            'username'     => $user['username'],
            'email'        => $user['email'],
            'passwordHash' => $user['password_hash'] ?? null,
            'firstName'    => $user['first_name'] ?? '',
            'lastName'     => $user['last_name'] ?? '',
            'isActive'     => (bool)$user['is_active'],
            'createdAt'    => $user['created_at'] ?? null,
            'updatedAt'    => $user['updated_at'] ?? null,
            'lastLogin'    => $user['last_login'] ?? null,
            'roles'        => $this->getUserRoles($userId),
            'permissions'  => $this->getUserPermissions($userId),
        ];
    }

    /**
     * Get user roles with permissions
     */
    private function getUserRoles(int $userId): array {
        $stmt = $this->pdo->prepare("
            SELECT
                r.roles_id,
                r.name,
                r.description,
                r.created_at
            FROM " . PREFIX . "_roles r
            INNER JOIN " . PREFIX . "_user_roles ur ON r.roles_id = ur.role_id
            WHERE ur.user_id = :userId
            ORDER BY r.name
        ");

        $stmt->execute(['userId' => $userId]);
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function($role) {
            return [
                'roles_id' => (int)$role['roles_id'],
                'name' => $role['name'],
                'description' => $role['description'] ?? '',
                'created_at' => $role['created_at'],
                'permissions' => $this->getRolePermissions((int)$role['roles_id'])
            ];
        }, $roles);
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
            ORDER BY p.resource, p.action
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
     * Get user-specific permissions (direct assignments)
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
            ORDER BY p.resource, p.action
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
}
