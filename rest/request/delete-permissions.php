<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Specialized DELETE handler for permissions endpoint
 * Handles: DELETE /api/permissions/{id}
 *
 * Deletes a permission from the system.
 * Requires permissions.delete permission.
 *
 * @remarks
 * When deleting a permission, associated role_permissions entries will be removed
 * due to foreign key constraints (if properly configured) or should be handled here.
 */
class requestDeletePermissions extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            $this->log('requestDeletePermissions::execute');
            $this->log(['requestDeletePermissions::request', $this->request]);

            // Require permission to delete permissions (existierende Permission: permissions.manage)
            CsrfHelper::requireValidToken();
            $user = $this->requireAnyPermission(['permissions.manage', 'admin.access']);

            // Get permission ID from request
            if (!isset($this->request['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Permission ID is required']);
                return;
            }

            $permissionId = is_array($this->request['id']) ? (int)$this->request['id'][0] : (int)$this->request['id'];

            // Check if permission exists
            $permission = $this->getPermission($permissionId);
            if (!$permission) {
                http_response_code(404);
                echo json_encode(['error' => 'Permission not found']);
                return;
            }

            // Delete associated role_permissions entries first
            $this->deleteRolePermissions($permissionId);

            // Delete the permission
            $this->deletePermission($permissionId);

            // Return success response with deleted permission info
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Permission deleted successfully',
                'deleted_permission' => [
                    'id' => (int)$permission['permissions_id'],
                    'name' => $permission['name'],
                    'resource' => $permission['resource'],
                    'action' => $permission['action']
                ]
            ]);

        } catch (\Throwable $e) {
            $this->handleError('Error deleting permission', $e);
        }
    }

    /**
     * Get permission by ID
     */
    private function getPermission(int $permissionId): array|false {
        $stmt = $this->pdo->prepare("
            SELECT
                permissions_id,
                name,
                resource,
                action,
                scope,
                description
            FROM " . PREFIX . "_permissions
            WHERE permissions_id = :id
            LIMIT 1
        ");

        $stmt->execute(['id' => $permissionId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Delete associated role_permissions entries
     *
     * @remarks
     * This ensures referential integrity if foreign key constraints are not set up.
     * If FK constraints exist with ON DELETE CASCADE, this is redundant but safe.
     */
    private function deleteRolePermissions(int $permissionId): void {
        $stmt = $this->pdo->prepare("
            DELETE FROM " . PREFIX . "_role_permissions
            WHERE permission_id = :permissionId
        ");

        $stmt->execute(['permissionId' => $permissionId]);

        $deletedCount = $stmt->rowCount();
        $this->log(['Deleted role_permissions entries', $deletedCount]);
    }

    /**
     * Delete the permission
     */
    private function deletePermission(int $permissionId): void {
        $stmt = $this->pdo->prepare("
            DELETE FROM " . PREFIX . "_permissions
            WHERE permissions_id = :id
        ");

        $stmt->execute(['id' => $permissionId]);

        if ($stmt->rowCount() === 0) {
            throw new \Exception('Permission could not be deleted');
        }
    }
}
