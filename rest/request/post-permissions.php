<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Specialized POST handler for permissions endpoint
 * Handles: POST /api/permissions
 *
 * Creates a new permission in the system.
 * Requires permissions.create permission.
 */
class requestPostPermissions extends RequestBase {
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
            $this->log('requestPostPermissions::execute');
            $this->log(['requestPostPermissions::request', $this->request]);
            $this->log(['requestPostPermissions::data', $this->data]);

            // Require permission to create permissions
            $user = $this->requirePermission('permissions.create');

            // Validate required fields
            $validation = $this->validatePermissionData($this->data);
            if ($validation !== true) {
                http_response_code(400);
                echo json_encode(['error' => $validation]);
                return;
            }

            // Check if permission name already exists
            if ($this->permissionNameExists($this->data['name'])) {
                http_response_code(409);
                echo json_encode(['error' => 'Permission with this name already exists']);
                return;
            }

            // Create the permission
            $permissionId = $this->createPermission($this->data);

            // Fetch and return the created permission
            $this->returnCreatedPermission($permissionId);

        } catch (\Throwable $e) {
            $this->handleError('Error creating permission', $e);
        }
    }

    /**
     * Validate permission data
     *
     * @return true|string Returns true if valid, error message otherwise
     */
    private function validatePermissionData(array $data): bool|string {
        // Required fields
        if (empty($data['name'])) {
            return 'Field "name" is required';
        }

        if (empty($data['resource'])) {
            return 'Field "resource" is required';
        }

        if (empty($data['action'])) {
            return 'Field "action" is required';
        }

        // Validate field lengths (according to schema)
        if (strlen($data['name']) > 100) {
            return 'Field "name" must not exceed 100 characters';
        }

        if (strlen($data['resource']) > 50) {
            return 'Field "resource" must not exceed 50 characters';
        }

        if (strlen($data['action']) > 50) {
            return 'Field "action" must not exceed 50 characters';
        }

        // Validate scope enum if provided
        if (isset($data['scope']) && !in_array($data['scope'], ['own', 'any', null], true)) {
            return 'Field "scope" must be either "own", "any", or null';
        }

        return true;
    }

    /**
     * Check if permission name already exists
     */
    private function permissionNameExists(string $name): bool {
        $stmt = $this->pdo->prepare("
            SELECT permissions_id
            FROM " . PREFIX . "_permissions
            WHERE name = :name
            LIMIT 1
        ");

        $stmt->execute(['name' => $name]);
        return $stmt->fetch() !== false;
    }

    /**
     * Create new permission in database
     */
    private function createPermission(array $data): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO " . PREFIX . "_permissions (
                name,
                resource,
                action,
                scope,
                description
            ) VALUES (
                :name,
                :resource,
                :action,
                :scope,
                :description
            )
        ");

        $stmt->execute([
            'name' => $data['name'],
            'resource' => $data['resource'],
            'action' => $data['action'],
            'scope' => $data['scope'] ?? null,
            'description' => $data['description'] ?? null
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Fetch and return the created permission
     */
    private function returnCreatedPermission(int $permissionId): void {
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
            http_response_code(500);
            echo json_encode(['error' => 'Failed to retrieve created permission']);
            return;
        }

        $permission['permissions_id'] = (int)$permission['permissions_id'];

        // Return as array for consistency with API conventions (ISO 25010 - Kompatibilität)
        http_response_code(201);
        header('Content-Type: application/json');
        echo json_encode([$permission]);
    }
}
