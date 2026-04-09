<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Specialized POST handler for navigation roles assignment
 * Handles: POST /api/navigation/{id}/roles
 */
class requestPostNavigationRoles extends RequestBase {
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
            $this->log('requestPostNavigationRoles::execute');
            $this->log(['requestPostNavigationRoles::request', $this->request]);
            $this->log(['requestPostNavigationRoles::data', $this->data]);

            // Require permission to manage navigation roles
            $currentUser = $this->requirePermission('navigation.manage');

            // Get target navigation ID
            if (!isset($this->request['navigation_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Navigation ID is required']);
                return;
            }

            $navigationId = (int)$this->request['navigation_id'];

            // Validate that navigation exists
            if (!$this->navigationExists($navigationId)) {
                http_response_code(404);
                echo json_encode(['error' => 'Navigation not found']);
                return;
            }

            // Get role ID from request
            if (isset($this->data['roleId'])) {
                $this->assignRole($navigationId, (int)$this->data['roleId'], (int)$currentUser['users_id']);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'roleId is required']);
                return;
            }

        } catch (\Throwable $e) {
            $this->handleError('Error assigning role to navigation', $e);
        }
    }

    /**
     * Check if navigation exists
     */
    private function navigationExists(int $navigationId): bool {
        $stmt = $this->pdo->prepare("
            SELECT navigations_id FROM " . PREFIX . "_navigations
            WHERE navigations_id = :navigationId
            LIMIT 1
        ");

        $stmt->execute(['navigationId' => $navigationId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Assign role to navigation
     */
    private function assignRole(int $navigationId, int $roleId, int $assignedBy): void {
        // Check if role exists
        $stmt = $this->pdo->prepare("
            SELECT roles_id, name, display_name
            FROM " . PREFIX . "_roles
            WHERE roles_id = :roleId
            LIMIT 1
        ");

        $stmt->execute(['roleId' => $roleId]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$role) {
            http_response_code(404);
            echo json_encode(['error' => 'Role not found']);
            return;
        }

        // Assign role (INSERT IGNORE to prevent duplicates)
        $stmt = $this->pdo->prepare("
            INSERT INTO " . PREFIX . "_navigation_roles (navigations_id, role_id, assigned_by)
            VALUES (:navigationId, :roleId, :assignedBy)
            ON DUPLICATE KEY UPDATE assigned_by = :assignedBy
        ");

        $stmt->execute([
            'navigationId' => $navigationId,
            'roleId' => $roleId,
            'assignedBy' => $assignedBy
        ]);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Role assigned successfully',
            'role' => [
                'roles_id' => (int)$role['roles_id'],
                'name' => $role['name'],
                'display_name' => $role['display_name']
            ]
        ]);
    }
}
