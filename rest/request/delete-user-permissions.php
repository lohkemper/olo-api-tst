<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * DELETE handler for user-permission assignment
 * Handles: DELETE /api/user-permissions/{userId}/{permissionId}
 */
class requestDeleteUserPermissions extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            $this->log('requestDeleteUserPermissions::execute');
            $this->log(['requestDeleteUserPermissions::request', $this->request]);

            // Require permission to manage permissions
            $currentUser = $this->requirePermission('permissions.manage');

            // Validate required parameters
            if (!isset($this->request['user_id']) || !isset($this->request['permission_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'user_id and permission_id are required']);
                return;
            }

            $userId = (int)$this->request['user_id'];
            $permissionId = (int)$this->request['permission_id'];

            // Delete the association
            $stmt = $this->pdo->prepare("
                DELETE FROM " . PREFIX . "_user_permissions
                WHERE user_id = :userId AND permission_id = :permissionId
            ");

            $stmt->execute([
                'userId' => $userId,
                'permissionId' => $permissionId
            ]);

            // Check if the association was actually deleted
            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'User-permission association not found']);
                return;
            }

            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode([
                'message' => 'Permission removed from user successfully',
                'user_id' => $userId,
                'permission_id' => $permissionId
            ]);

        } catch (\Throwable $e) {
            $this->handleError('Error removing permission from user', $e);
        }
    }
}
