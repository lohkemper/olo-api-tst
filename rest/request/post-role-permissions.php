<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST handler for role-permission assignment
 * Handles: POST /api/role-permissions
 */
class requestPostRolePermissions extends RequestBase {
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
            $this->log('requestPostRolePermissions::execute');
            $this->log(['requestPostRolePermissions::request', $this->request]);
            $this->log(['requestPostRolePermissions::data', $this->data]);

            // Require permission to manage permissions
            $currentUser = $this->requirePermission('permissions.manage');

            // Validate required fields
            if (!isset($this->data['role_id']) || !isset($this->data['permission_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'role_id and permission_id are required']);
                return;
            }

            $roleId = (int)$this->data['role_id'];
            $permissionId = (int)$this->data['permission_id'];

            // Validate that role exists
            if (!$this->roleExists($roleId)) {
                http_response_code(404);
                echo json_encode(['error' => 'Role not found']);
                return;
            }

            // Validate that permission exists
            if (!$this->permissionExists($permissionId)) {
                http_response_code(404);
                echo json_encode(['error' => 'Permission not found']);
                return;
            }

            // Assign permission to role (INSERT IGNORE to prevent duplicates)
            $stmt = $this->pdo->prepare("
                INSERT INTO " . PREFIX . "_role_permissions (role_id, permission_id)
                VALUES (:roleId, :permissionId)
                ON DUPLICATE KEY UPDATE role_id = role_id
            ");

            $stmt->execute([
                'roleId' => $roleId,
                'permissionId' => $permissionId
            ]);

            // Get the created association
            $stmt = $this->pdo->prepare("
                SELECT rp.*,
                       p.name as permission_name,
                       r.name as role_name
                FROM " . PREFIX . "_role_permissions rp
                JOIN " . PREFIX . "_permissions p ON rp.permission_id = p.permissions_id
                JOIN " . PREFIX . "_roles r ON rp.role_id = r.roles_id
                WHERE rp.role_id = :roleId AND rp.permission_id = :permissionId
                LIMIT 1
            ");

            $stmt->execute([
                'roleId' => $roleId,
                'permissionId' => $permissionId
            ]);

            $association = $stmt->fetch(PDO::FETCH_ASSOC);

            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode([
                [
                    'role_id' => (int)$association['role_id'],
                    'permission_id' => (int)$association['permission_id'],
                    'created_at' => $association['created_at'],
                    'permission_name' => $association['permission_name'],
                    'role_name' => $association['role_name']
                ]
            ]);

        } catch (\Throwable $e) {
            $this->handleError('Error assigning permission to role', $e);
        }
    }

    /**
     * Check if role exists
     */
    private function roleExists(int $roleId): bool {
        $stmt = $this->pdo->prepare("
            SELECT roles_id FROM " . PREFIX . "_roles
            WHERE roles_id = :roleId
            LIMIT 1
        ");

        $stmt->execute(['roleId' => $roleId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Check if permission exists
     */
    private function permissionExists(int $permissionId): bool {
        $stmt = $this->pdo->prepare("
            SELECT permissions_id FROM " . PREFIX . "_permissions
            WHERE permissions_id = :permissionId
            LIMIT 1
        ");

        $stmt->execute(['permissionId' => $permissionId]);
        return $stmt->fetch() !== false;
    }
}
