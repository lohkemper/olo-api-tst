<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Specialized POST handler for user roles assignment
 * Handles: POST /api/users/{id}/roles
 */
class requestPostUserRoles extends RequestBase {
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
            $this->log('requestPostUserRoles::execute');
            $this->log(['requestPostUserRoles::request', $this->request]);
            $this->log(['requestPostUserRoles::data', $this->data]);

            // Require permission to manage roles
            CsrfHelper::requireValidToken();
            $currentUser = $this->requirePermission('roles.manage');

            // Get target user ID
            if (!isset($this->request['user_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'User ID is required']);
                return;
            }

            $userId = (int)$this->request['user_id'];

            // Validate that user exists
            if (!$this->userExists($userId)) {
                http_response_code(404);
                echo json_encode(['error' => 'User not found']);
                return;
            }

            // Get role ID or name from request
            if (isset($this->data['roleId'])) {
                $this->assignRoleById($userId, (int)$this->data['roleId'], (int)$currentUser['users_id']);
            } elseif (isset($this->data['roleName'])) {
                $this->assignRoleByName($userId, $this->data['roleName'], (int)$currentUser['users_id']);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'roleId or roleName is required']);
                return;
            }

        } catch (\Throwable $e) {
            $this->handleError('Error assigning role to user', $e);
        }
    }

    /**
     * Check if user exists
     */
    private function userExists(int $userId): bool {
        $stmt = $this->pdo->prepare("
            SELECT users_id FROM " . PREFIX . "_users
            WHERE users_id = :userId
            LIMIT 1
        ");

        $stmt->execute(['userId' => $userId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Assign role to user by role ID
     */
    private function assignRoleById(int $userId, int $roleId, int $assignedBy): void {
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
            INSERT INTO " . PREFIX . "_user_roles (user_id, role_id, assigned_by)
            VALUES (:userId, :roleId, :assignedBy)
            ON DUPLICATE KEY UPDATE assigned_by = :assignedBy
        ");

        $stmt->execute([
            'userId' => $userId,
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

    /**
     * Assign role to user by role name
     */
    private function assignRoleByName(int $userId, string $roleName, int $assignedBy): void {
        // Get role by name
        $stmt = $this->pdo->prepare("
            SELECT roles_id, name, display_name
            FROM " . PREFIX . "_roles
            WHERE name = :roleName
            LIMIT 1
        ");

        $stmt->execute(['roleName' => $roleName]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$role) {
            http_response_code(404);
            echo json_encode(['error' => 'Role not found']);
            return;
        }

        // Assign role
        $this->assignRoleById($userId, (int)$role['roles_id'], $assignedBy);
    }
}
