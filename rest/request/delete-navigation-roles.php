<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Specialized DELETE handler for navigation roles
 * Handles: DELETE /api/navigation/{id}/roles?roleId={roleId}
 */
class requestDeleteNavigationRoles extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            $this->log('requestDeleteNavigationRoles::execute');
            $this->log(['requestDeleteNavigationRoles::request', $this->request]);

            // Require permission to manage navigation roles
            $this->requirePermission('navigation.manage');

            // Get navigation ID
            if (!isset($this->request['navigation_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Navigation ID is required']);
                return;
            }

            $navigationId = (int)$this->request['navigation_id'];

            // Get role ID from query params
            if (!isset($this->request['roleId'])) {
                http_response_code(400);
                echo json_encode(['error' => 'roleId is required']);
                return;
            }

            $roleId = (int)$this->request['roleId'];

            // Delete the assignment
            $stmt = $this->pdo->prepare("
                DELETE FROM " . PREFIX . "_navigation_roles
                WHERE navigations_id = :navigationId AND role_id = :roleId
            ");

            $stmt->execute([
                'navigationId' => $navigationId,
                'roleId' => $roleId
            ]);

            $deleted = $stmt->rowCount();

            if ($deleted > 0) {
                http_response_code(200);
                echo json_encode([
                    'success' => true,
                    'message' => 'Role removed from navigation'
                ]);
            } else {
                http_response_code(404);
                echo json_encode([
                    'error' => 'Assignment not found'
                ]);
            }

        } catch (\Throwable $e) {
            $this->handleError('Error removing role from navigation', $e);
        }
    }
}
