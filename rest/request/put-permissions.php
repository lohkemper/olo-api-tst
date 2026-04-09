<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Specialized PUT handler for permissions endpoint
 * Handles: PUT /api/permissions/{id}
 *
 * Updates an existing permission.
 * Requires permissions.update permission.
 */
class requestPutPermissions extends RequestBase {
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
            $this->log('requestPutPermissions::execute');
            $this->log(['requestPutPermissions::request', $this->request]);
            $this->log(['requestPutPermissions::data', $this->data]);

            // Require permission to update permissions
            $user = $this->requirePermission('permissions.update');

            // Get permission ID from request
            if (!isset($this->request['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Permission ID is required']);
                return;
            }

            $permissionId = is_array($this->request['id']) ? (int)$this->request['id'][0] : (int)$this->request['id'];

            // Check if permission exists
            if (!$this->permissionExists($permissionId)) {
                http_response_code(404);
                echo json_encode(['error' => 'Permission not found']);
                return;
            }

            // Validate data
            $validation = $this->validatePermissionData($this->data);
            if ($validation !== true) {
                http_response_code(400);
                echo json_encode(['error' => $validation]);
                return;
            }

            // Check for duplicate name (excluding current permission)
            if (isset($this->data['name']) && $this->isNameDuplicate($this->data['name'], $permissionId)) {
                http_response_code(409);
                echo json_encode(['error' => 'Permission with this name already exists']);
                return;
            }

            // Update the permission
            $this->updatePermission($permissionId, $this->data);

            // Fetch and return the updated permission
            $this->returnUpdatedPermission($permissionId);

        } catch (\Throwable $e) {
            $this->handleError('Error updating permission', $e);
        }
    }

    /**
     * Check if permission exists
     */
    private function permissionExists(int $permissionId): bool {
        $stmt = $this->pdo->prepare("
            SELECT permissions_id
            FROM " . PREFIX . "_permissions
            WHERE permissions_id = :id
            LIMIT 1
        ");

        $stmt->execute(['id' => $permissionId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Validate permission data for update
     * All fields are optional for PUT, but must be valid if provided
     *
     * @return true|string Returns true if valid, error message otherwise
     */
    private function validatePermissionData(array $data): bool|string {
        // Validate field lengths if provided
        if (isset($data['name']) && strlen($data['name']) > 100) {
            return 'Field "name" must not exceed 100 characters';
        }

        if (isset($data['resource']) && strlen($data['resource']) > 50) {
            return 'Field "resource" must not exceed 50 characters';
        }

        if (isset($data['action']) && strlen($data['action']) > 50) {
            return 'Field "action" must not exceed 50 characters';
        }

        // Validate scope enum if provided
        if (isset($data['scope']) && !in_array($data['scope'], ['own', 'any', null], true)) {
            return 'Field "scope" must be either "own", "any", or null';
        }

        // Ensure at least one field is provided for update
        $updateableFields = ['name', 'resource', 'action', 'scope', 'description'];
        $hasUpdateField = false;
        foreach ($updateableFields as $field) {
            if (isset($data[$field])) {
                $hasUpdateField = true;
                break;
            }
        }

        if (!$hasUpdateField) {
            return 'At least one field must be provided for update';
        }

        return true;
    }

    /**
     * Check if name is duplicate (excluding current permission)
     */
    private function isNameDuplicate(string $name, int $permissionId): bool {
        $stmt = $this->pdo->prepare("
            SELECT permissions_id
            FROM " . PREFIX . "_permissions
            WHERE name = :name AND permissions_id != :id
            LIMIT 1
        ");

        $stmt->execute(['name' => $name, 'id' => $permissionId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Update permission in database
     */
    private function updatePermission(int $permissionId, array $data): void {
        // Build dynamic UPDATE query based on provided fields
        $updateFields = [];
        $params = ['id' => $permissionId];

        if (isset($data['name'])) {
            $updateFields[] = 'name = :name';
            $params['name'] = $data['name'];
        }

        if (isset($data['resource'])) {
            $updateFields[] = 'resource = :resource';
            $params['resource'] = $data['resource'];
        }

        if (isset($data['action'])) {
            $updateFields[] = 'action = :action';
            $params['action'] = $data['action'];
        }

        if (isset($data['scope'])) {
            $updateFields[] = 'scope = :scope';
            $params['scope'] = $data['scope'];
        }

        if (isset($data['description'])) {
            $updateFields[] = 'description = :description';
            $params['description'] = $data['description'];
        }

        $sql = "
            UPDATE " . PREFIX . "_permissions
            SET " . implode(', ', $updateFields) . "
            WHERE permissions_id = :id
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Fetch and return the updated permission
     */
    private function returnUpdatedPermission(int $permissionId): void {
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
            echo json_encode(['error' => 'Failed to retrieve updated permission']);
            return;
        }

        $permission['permissions_id'] = (int)$permission['permissions_id'];

        // Return as array for consistency with API conventions (ISO 25010 - Kompatibilität)
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([$permission]);
    }
}
