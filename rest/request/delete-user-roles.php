<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * DELETE handler for user-role assignment
 * Handles: DELETE /api/users/{userId}/roles/{roleId}
 */
class requestDeleteUserRoles extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            $this->log('requestDeleteUserRoles::execute');
            $this->log(['requestDeleteUserRoles::request', $this->request]);

            // Require permission to manage roles
            CsrfHelper::requireValidToken();
            $currentUser = $this->requirePermission('roles.manage');

            // Validate required parameters
            if (!isset($this->request['user_id']) || !isset($this->request['role_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'user_id and role_id are required']);
                return;
            }

            $userId = (int)$this->request['user_id'];
            $roleId = (int)$this->request['role_id'];

            // Delete the association
            $stmt = $this->pdo->prepare("
                DELETE FROM " . PREFIX . "_user_roles
                WHERE user_id = :userId AND role_id = :roleId
            ");

            $stmt->execute([
                'userId' => $userId,
                'roleId' => $roleId
            ]);

            // Check if the association was actually deleted
            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'User-role association not found']);
                return;
            }

            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode([
                'message' => 'Role removed from user successfully',
                'user_id' => $userId,
                'role_id' => $roleId
            ]);

        } catch (\Throwable $e) {
            $this->handleError('Error removing role from user', $e);
        }
    }
}
