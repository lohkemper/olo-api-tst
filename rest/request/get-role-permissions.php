<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET handler for role-permissions
 * Handles: GET /api/role-permissions?role_id={id} or ?permission_id={id}
 */
class requestGetRolePermissions extends RequestBase {
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
            $this->log('requestGetRolePermissions::execute');
            $this->log(['requestGetRolePermissions::request', $this->request]);

            // Require permission to view permissions
            $currentUser = $this->requirePermission('permissions.read');

            // Check if filtering by role_id or permission_id
            if (isset($this->request['role_id'])) {
                $this->getByRoleId((int)$this->request['role_id']);
            } elseif (isset($this->request['permission_id'])) {
                $this->getByPermissionId((int)$this->request['permission_id']);
            } else {
                // Get all associations
                $this->getAll();
            }

        } catch (\Throwable $e) {
            $this->handleError('Error fetching role-permissions', $e);
        }
    }

    /**
     * Get all role-permission associations
     */
    private function getAll(): void {
        $stmt = $this->pdo->query("
            SELECT rp.*,
                   p.name as permission_name,
                   p.resource,
                   p.action,
                   p.scope,
                   r.name as role_name,
                   r.display_name as role_display_name
            FROM " . PREFIX . "_role_permissions rp
            JOIN " . PREFIX . "_permissions p ON rp.permission_id = p.permissions_id
            JOIN " . PREFIX . "_roles r ON rp.role_id = r.roles_id
            ORDER BY r.name, p.resource, p.action
        ");

        $associations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($this->formatAssociations($associations));
    }

    /**
     * Get associations by role ID
     */
    private function getByRoleId(int $roleId): void {
        $stmt = $this->pdo->prepare("
            SELECT rp.*,
                   p.name as permission_name,
                   p.resource,
                   p.action,
                   p.scope,
                   r.name as role_name,
                   r.display_name as role_display_name
            FROM " . PREFIX . "_role_permissions rp
            JOIN " . PREFIX . "_permissions p ON rp.permission_id = p.permissions_id
            JOIN " . PREFIX . "_roles r ON rp.role_id = r.roles_id
            WHERE rp.role_id = :roleId
            ORDER BY p.resource, p.action
        ");

        $stmt->execute(['roleId' => $roleId]);
        $associations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($this->formatAssociations($associations));
    }

    /**
     * Get associations by permission ID
     */
    private function getByPermissionId(int $permissionId): void {
        $stmt = $this->pdo->prepare("
            SELECT rp.*,
                   p.name as permission_name,
                   p.resource,
                   p.action,
                   p.scope,
                   r.name as role_name,
                   r.display_name as role_display_name
            FROM " . PREFIX . "_role_permissions rp
            JOIN " . PREFIX . "_permissions p ON rp.permission_id = p.permissions_id
            JOIN " . PREFIX . "_roles r ON rp.role_id = r.roles_id
            WHERE rp.permission_id = :permissionId
            ORDER BY r.name
        ");

        $stmt->execute(['permissionId' => $permissionId]);
        $associations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($this->formatAssociations($associations));
    }

    /**
     * Format associations for output
     */
    private function formatAssociations(array $associations): array {
        return array_map(function($assoc) {
            return [
                'role_id' => (int)$assoc['role_id'],
                'permission_id' => (int)$assoc['permission_id'],
                'created_at' => $assoc['created_at'],
                'permission_name' => $assoc['permission_name'],
                'resource' => $assoc['resource'],
                'action' => $assoc['action'],
                'scope' => $assoc['scope'],
                'role_name' => $assoc['role_name'],
                'role_display_name' => $assoc['role_display_name']
            ];
        }, $associations);
    }
}
