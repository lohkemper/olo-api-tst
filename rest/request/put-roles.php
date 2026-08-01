<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Specialized PUT handler for roles endpoint
 * Handles: PUT /api/roles/{id}
 *
 * Aktualisiert eine bestehende Rolle (display_name, description; name optional).
 * Erfordert roles.manage oder admin.access.
 */
class requestPutRoles extends RequestBase {
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
            $this->log('requestPutRoles::execute');
            $this->log(['requestPutRoles::request', $this->request]);
            $this->log(['requestPutRoles::data', $this->data]);

            CsrfHelper::requireValidToken();
            $this->requireAnyPermission(['roles.manage', 'admin.access']);

            if (!isset($this->request['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Role ID is required']);
                return;
            }

            $roleId = is_array($this->request['id']) ? (int)$this->request['id'][0] : (int)$this->request['id'];

            $role = $this->getRole($roleId);
            if (!$role) {
                http_response_code(404);
                echo json_encode(['error' => 'Role not found']);
                return;
            }

            // Aufbau des Updates nur aus erlaubten, vorhandenen Feldern
            $allowed = ['name', 'display_name', 'description'];
            $sets = [];
            $params = ['id' => $roleId];
            foreach ($allowed as $field) {
                if (array_key_exists($field, $this->data)) {
                    $sets[] = "`$field` = :$field";
                    $params[$field] = $this->data[$field];
                }
            }

            if (empty($sets)) {
                http_response_code(400);
                echo json_encode(['error' => 'No updatable fields provided']);
                return;
            }

            // Duplicate-Name-Check (ausser eigener Rolle)
            if (isset($this->data['name'])) {
                $dup = $this->pdo->prepare(
                    "SELECT roles_id FROM " . PREFIX . "_roles WHERE name = :name AND roles_id != :id LIMIT 1"
                );
                $dup->execute(['name' => $this->data['name'], 'id' => $roleId]);
                if ($dup->fetch()) {
                    http_response_code(409);
                    echo json_encode(['error' => 'Role with this name already exists']);
                    return;
                }
            }

            $stmt = $this->pdo->prepare(
                "UPDATE " . PREFIX . "_roles SET " . implode(', ', $sets) . " WHERE roles_id = :id"
            );
            $stmt->execute($params);

            $updated = $this->getRole($roleId);
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode([[
                'roles_id' => (int)$updated['roles_id'],
                'name' => $updated['name'],
                'display_name' => $updated['display_name'] ?? '',
                'description' => $updated['description'] ?? '',
            ]]);

        } catch (\Throwable $e) {
            $this->handleError('Error updating role', $e);
        }
    }

    private function getRole(int $roleId): array|false {
        $stmt = $this->pdo->prepare(
            "SELECT roles_id, name, display_name, description FROM " . PREFIX . "_roles WHERE roles_id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $roleId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
