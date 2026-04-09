<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * DELETE handler for role-permission removal
 * Handles: DELETE /api/role-permissions/{roleId}/{permissionId}
 */
class requestDeleteRolePermissions extends RequestBase {
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
            $this->log('requestDeleteRolePermissions::execute');
            $this->log(['requestDeleteRolePermissions::request', $this->request]);
            $this->log(['requestDeleteRolePermissions::data', $this->data]);

            // Require permission to manage permissions
            $currentUser = $this->requirePermission('permissions.manage');

            // Get role_id and permission_id from request path
            // Expected format: /role-permissions/{roleId}/{permissionId}
            if (!isset($this->request['role_id']) || !isset($this->request['permission_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'role_id and permission_id are required']);
                return;
            }

            $roleId = (int)$this->request['role_id'];
            $permissionId = (int)$this->request['permission_id'];

            // Check if association exists
            $stmt = $this->pdo->prepare("
                SELECT * FROM " . PREFIX . "_role_permissions
                WHERE role_id = :roleId AND permission_id = :permissionId
                LIMIT 1
            ");

            $stmt->execute([
                'roleId' => $roleId,
                'permissionId' => $permissionId
            ]);

            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Role-Permission association not found']);
                return;
            }

            // Delete the association
            $stmt = $this->pdo->prepare("
                DELETE FROM " . PREFIX . "_role_permissions
                WHERE role_id = :roleId AND permission_id = :permissionId
            ");

            $stmt->execute([
                'roleId' => $roleId,
                'permissionId' => $permissionId
            ]);

            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Permission removed from role successfully'
            ]);

        } catch (\Throwable $e) {
            $this->handleError('Error removing permission from role', $e);
        }
    }
}
