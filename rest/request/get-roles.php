<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Specialized GET handler for roles endpoint
 * Handles: GET /api/roles, GET /api/roles/{id}
 */
class requestGetRoles extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            $this->log('requestGetRoles::execute');
            $this->log(['requestGetRoles::request', $this->request]);

            // Require authentication and permission to read roles
            $user = $this->requireAnyPermission(['roles.read', 'admin.access']);

            // Check if specific role ID is requested
            if (isset($this->request['id'])) {
                // ID can be array (from setPath) or scalar - extract first value
                $roleId = is_array($this->request['id']) ? (int)$this->request['id'][0] : (int)$this->request['id'];
                $this->handleGetSingleRole($roleId);
            } else {
                $this->handleGetAllRoles();
            }

        } catch (\Throwable $e) {
            $this->handleError('Error executing GET roles request', $e);
        }
    }

    /**
     * GET /api/roles
     * Returns all roles with their permissions
     */
    private function handleGetAllRoles(): void {
        $stmt = $this->pdo->prepare("
            SELECT
                roles_id,
                name,
                display_name,
                description,
                created_at,
                updated_at
            FROM " . PREFIX . "_roles
            ORDER BY
                CASE name
                    WHEN 'super_admin' THEN 1
                    WHEN 'admin' THEN 2
                    WHEN 'moderator' THEN 3
                    WHEN 'user' THEN 4
                    WHEN 'guest' THEN 5
                    ELSE 6
                END
        ");

        $stmt->execute();
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Load permissions for each role
        foreach ($roles as &$role) {
            $role['roles_id'] = (int)$role['roles_id'];
            $role['permissions'] = $this->getRolePermissions((int)$role['roles_id']);
        }

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($roles);
    }

    /**
     * GET /api/roles/{id}
     * Returns a single role with its permissions as an array (for consistency)
     */
    private function handleGetSingleRole(int $roleId): void {
        $stmt = $this->pdo->prepare("
            SELECT
                roles_id,
                name,
                display_name,
                description,
                created_at,
                updated_at
            FROM " . PREFIX . "_roles
            WHERE roles_id = :id
            LIMIT 1
        ");

        $stmt->execute(['id' => $roleId]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$role) {
            http_response_code(404);
            echo json_encode(['error' => 'Role not found']);
            return;
        }

        $role['roles_id'] = (int)$role['roles_id'];
        $role['permissions'] = $this->getRolePermissions($roleId);

        // Return as array for consistency with getAll endpoint
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([$role]);
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

        // Format permissions
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
