<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST handler for user-permission assignment
 * Handles: POST /api/user-permissions
 */
class requestPostUserPermissions extends RequestBase {
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
            $this->log('requestPostUserPermissions::execute');
            $this->log(['requestPostUserPermissions::request', $this->request]);
            $this->log(['requestPostUserPermissions::data', $this->data]);

            // Require permission to manage permissions
            CsrfHelper::requireValidToken();
            $currentUser = $this->requirePermission('permissions.manage');

            // Validate required fields
            if (!isset($this->data['user_id']) || !isset($this->data['permission_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'user_id and permission_id are required']);
                return;
            }

            $userId = (int)$this->data['user_id'];
            $permissionId = (int)$this->data['permission_id'];

            // Validate that user exists
            if (!$this->userExists($userId)) {
                http_response_code(404);
                echo json_encode(['error' => 'User not found']);
                return;
            }

            // Validate that permission exists
            if (!$this->permissionExists($permissionId)) {
                http_response_code(404);
                echo json_encode(['error' => 'Permission not found']);
                return;
            }

            // Assign permission to user (INSERT IGNORE to prevent duplicates)
            $stmt = $this->pdo->prepare("
                INSERT INTO " . PREFIX . "_user_permissions (user_id, permission_id, granted_by)
                VALUES (:userId, :permissionId, :grantedBy)
                ON DUPLICATE KEY UPDATE user_id = user_id
            ");

            $stmt->execute([
                'userId' => $userId,
                'permissionId' => $permissionId,
                'grantedBy' => (int)$currentUser['users_id']
            ]);

            // Get the created association
            $stmt = $this->pdo->prepare("
                SELECT up.*,
                       p.name as permission_name,
                       p.resource,
                       p.action,
                       p.scope,
                       p.description,
                       u.username
                FROM " . PREFIX . "_user_permissions up
                JOIN " . PREFIX . "_permissions p ON up.permission_id = p.permissions_id
                JOIN " . PREFIX . "_users u ON up.user_id = u.users_id
                WHERE up.user_id = :userId AND up.permission_id = :permissionId
                LIMIT 1
            ");

            $stmt->execute([
                'userId' => $userId,
                'permissionId' => $permissionId
            ]);

            $association = $stmt->fetch(PDO::FETCH_ASSOC);

            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode([
                [
                    'user_id' => (int)$association['user_id'],
                    'permission_id' => (int)$association['permission_id'],
                    'granted_at' => $association['granted_at'],
                    'granted_by' => $association['granted_by'] ? (int)$association['granted_by'] : null,
                    'expires_at' => $association['expires_at'],
                    'permission_name' => $association['permission_name'],
                    'resource' => $association['resource'],
                    'action' => $association['action'],
                    'scope' => $association['scope'],
                    'description' => $association['description'] ?? '',
                    'username' => $association['username']
                ]
            ]);

        } catch (\Throwable $e) {
            $this->handleError('Error assigning permission to user', $e);
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
