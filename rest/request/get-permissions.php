<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Specialized GET handler for permissions endpoint
 * Handles: GET /api/permissions, GET /api/permissions/{id}
 */
class requestGetPermissions extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            $this->log('requestGetPermissions::execute');
            $this->log(['requestGetPermissions::request', $this->request]);

            // Require authentication and permission to read permissions
            $user = $this->requireAnyPermission(['permissions.read', 'admin.access']);

            // Check if specific permission ID is requested
            if (isset($this->request['id'])) {
                // ID can be array (from setPath) or scalar - extract first value
                $permissionId = is_array($this->request['id']) ? (int)$this->request['id'][0] : (int)$this->request['id'];
                $this->handleGetSinglePermission($permissionId);
            } else {
                $this->handleGetAllPermissions();
            }

        } catch (\Throwable $e) {
            $this->handleError('Error executing GET permissions request', $e);
        }
    }

    /**
     * GET /api/permissions
     * Returns all permissions as array
     */
    private function handleGetAllPermissions(): void {
        $stmt = $this->pdo->prepare("
            SELECT
                permissions_id,
                name,
                resource,
                action,
                scope,
                description,
                created_at
            FROM " . PREFIX . "_permissions
            ORDER BY resource, action, scope
        ");

        $stmt->execute();
        $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format permissions (camelCase, matched Frontend-Interface Permission)
        $formattedPermissions = array_map(function($permission) {
            return [
                'id' => (int)$permission['permissions_id'],
                'name' => $permission['name'],
                'resource' => $permission['resource'],
                'action' => $permission['action'],
                'scope' => $permission['scope'],
                'description' => $permission['description'] ?? '',
                'createdAt' => $permission['created_at']
            ];
        }, $permissions);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($formattedPermissions);
    }

    /**
     * GET /api/permissions/{id}
     * Returns a single permission
     */
    private function handleGetSinglePermission(int $permissionId): void {
        $stmt = $this->pdo->prepare("
            SELECT
                permissions_id,
                name,
                resource,
                action,
                scope,
                description,
                created_at
            FROM " . PREFIX . "_permissions
            WHERE permissions_id = :id
            LIMIT 1
        ");

        $stmt->execute(['id' => $permissionId]);
        $permission = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$permission) {
            http_response_code(404);
            echo json_encode(['error' => 'Permission not found']);
            return;
        }

        // Format (camelCase, matched Frontend-Interface Permission)
        $formatted = [
            'id' => (int)$permission['permissions_id'],
            'name' => $permission['name'],
            'resource' => $permission['resource'],
            'action' => $permission['action'],
            'scope' => $permission['scope'],
            'description' => $permission['description'] ?? '',
            'createdAt' => $permission['created_at'],
        ];

        // Return as array for consistent API responses (ISO 25010 - Kompatibilität)
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([$formatted]);
    }

}
