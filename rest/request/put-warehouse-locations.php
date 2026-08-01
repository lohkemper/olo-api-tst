<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * PUT handler for warehouse-locations endpoint
 * Handles:
 * - PUT /api/warehouse-locations/{id} (update location)
 * - PUT /api/warehouse-locations/{id}/move (move location to new parent)
 *
 * @version 1.0.0
 */
class requestPutWarehouseLocations extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            global $_PUT;
            $this->log('requestPutWarehouseLocations::execute');
            $this->log(['requestPutWarehouseLocations::request', $this->request]);
            $this->log(['requestPutWarehouseLocations::_PUT', $_PUT]);

            // Requires authentication
            $user = $this->getCurrentUser();
            if (!$user) {
                http_response_code(401);
                echo json_encode(['error' => 'Authentication required']);
                return;
            }

            $userId = (int)$user['users_id'];

            // ID is required
            if (!isset($this->request['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Location ID is required']);
                return;
            }

            $locationId = is_array($this->request['id'])
                ? (int)$this->request['id'][0]
                : (int)$this->request['id'];

            // Check if this is a move operation
            if (isset($this->request['subroute']) && $this->request['subroute'] === 'move') {
                $this->handleMoveLocation($userId, $locationId);
            } else {
                $this->handleUpdateLocation($userId, $locationId);
            }

        } catch (\Throwable $e) {
            $this->handleError('Error updating warehouse location', $e);
        }
    }

    /**
     * PUT /api/warehouse-locations/{id} - Update location
     */
    private function handleUpdateLocation(int $userId, int $locationId): void {
        global $_PUT;

        // Verify location exists and belongs to user
        $sql = "SELECT * FROM " . PREFIX . "_warehouse_locations WHERE locations_id = ? AND user_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$locationId, $userId]);
        $existingLocation = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existingLocation) {
            http_response_code(404);
            echo json_encode(['error' => 'Location not found']);
            return;
        }

        // Build update SQL dynamically
        $updates = [];
        $params = [];

        if (isset($_PUT['name'])) {
            $updates[] = "name = ?";
            $params[] = $_PUT['name'];
        }

        if (isset($_PUT['type'])) {
            $updates[] = "type = ?";
            $params[] = $_PUT['type'];
        }

        if (isset($_PUT['description'])) {
            $updates[] = "description = ?";
            $params[] = $_PUT['description'];
        }

        if (isset($_PUT['meta'])) {
            $updates[] = "meta = ?";
            $params[] = json_encode($_PUT['meta']);
        }

        // Maße + Grid (alle nullable INT UNSIGNED)
        foreach (['grid_rows', 'grid_cols', 'width_cm', 'height_cm', 'depth_cm'] as $col) {
            if (array_key_exists($col, $_PUT)) {
                $updates[] = "$col = ?";
                $params[] = $this->nullableUint($_PUT[$col]);
            }
        }

        // Handle parent_id updates (null is allowed for root locations)
        if (array_key_exists('parent_id', $_PUT)) {
            $newParentId = $_PUT['parent_id'];

            // Validate new parent if not null
            if ($newParentId !== null) {
                $newParentId = (int)$newParentId;

                // Parent must exist and belong to user
                $sql = "SELECT * FROM " . PREFIX . "_warehouse_locations WHERE locations_id = ? AND user_id = ?";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([$newParentId, $userId]);
                $newParent = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$newParent) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Parent location not found']);
                    return;
                }

                // Prevent circular reference: newParent cannot be a descendant of location
                if ($this->isDescendant($newParentId, $locationId)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Cannot move location to its own descendant']);
                    return;
                }
            }

            $updates[] = "parent_id = ?";
            $params[] = $newParentId;
        }

        if (empty($updates)) {
            http_response_code(400);
            echo json_encode(['error' => 'No fields to update']);
            return;
        }

        $params[] = $locationId;

        $sql = "
            UPDATE " . PREFIX . "_warehouse_locations
            SET " . implode(', ', $updates) . "
            WHERE locations_id = ?
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        // If name or parent changed, update children
        if (isset($_PUT['name']) || array_key_exists('parent_id', $_PUT)) {
            $this->pdo->query("CALL sp_update_warehouse_location_children($locationId)");
        }

        // Fetch updated location
        $sql = "SELECT * FROM " . PREFIX . "_warehouse_locations WHERE locations_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$locationId]);
        $location = $stmt->fetch(PDO::FETCH_ASSOC);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([$location]);
    }

    /**
     * PUT /api/warehouse-locations/{id}/move - Move location to new parent
     */
    private function handleMoveLocation(int $userId, int $locationId): void {
        global $_PUT;

        // Verify location exists and belongs to user
        $sql = "SELECT * FROM " . PREFIX . "_warehouse_locations WHERE locations_id = ? AND user_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$locationId, $userId]);
        $location = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$location) {
            http_response_code(404);
            echo json_encode(['error' => 'Location not found']);
            return;
        }

        $newParentId = $_PUT['parent_id'] ?? null;

        // Validate new parent
        if ($newParentId !== null) {
            $newParentId = (int)$newParentId;

            // Parent must exist and belong to user
            $sql = "SELECT * FROM " . PREFIX . "_warehouse_locations WHERE locations_id = ? AND user_id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$newParentId, $userId]);
            $newParent = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$newParent) {
                http_response_code(400);
                echo json_encode(['error' => 'Parent location not found']);
                return;
            }

            // Prevent circular reference: newParent cannot be a descendant of location
            if ($this->isDescendant($newParentId, $locationId)) {
                http_response_code(400);
                echo json_encode(['error' => 'Cannot move location to its own descendant']);
                return;
            }
        }

        // Update parent_id (trigger will recalculate path and level)
        $sql = "UPDATE " . PREFIX . "_warehouse_locations SET parent_id = ? WHERE locations_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$newParentId, $locationId]);

        // Update all children recursively
        $this->pdo->query("CALL sp_update_warehouse_location_children($locationId)");

        // Fetch updated location and all affected children
        $sql = "
            SELECT *
            FROM " . PREFIX . "_warehouse_locations
            WHERE user_id = ?
              AND (locations_id = ? OR path LIKE ?)
            ORDER BY level ASC
        ";

        $pathPattern = '%'; // We'll get the new path first
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId, $locationId]);
        $updatedLocation = $stmt->fetch(PDO::FETCH_ASSOC);

        // Now get all descendants
        $pathPattern = $updatedLocation['path'] . '/%';
        $sql = "
            SELECT *
            FROM " . PREFIX . "_warehouse_locations
            WHERE user_id = ?
              AND (locations_id = ? OR path LIKE ?)
            ORDER BY level ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId, $locationId, $pathPattern]);
        $affectedLocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($affectedLocations);
    }

    /**
     * Normalisiert Eingabe zu UNSIGNED INT oder NULL.
     * Akzeptiert nur positive Ganzzahlen; leere Strings/0/non-numeric → NULL.
     */
    private function nullableUint(mixed $value): ?int {
        if ($value === null || $value === '' || $value === false) return null;
        if (!is_numeric($value)) return null;
        $i = (int)$value;
        return $i > 0 ? $i : null;
    }

    /**
     * Check if potentialDescendant is a descendant of ancestor
     */
    private function isDescendant(int $potentialDescendant, int $ancestor): bool {
        $sql = "
            SELECT path
            FROM " . PREFIX . "_warehouse_locations
            WHERE locations_id = ?
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$potentialDescendant]);
        $descendant = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$descendant) {
            return false;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$ancestor]);
        $ancestorLocation = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ancestorLocation) {
            return false;
        }

        // Check if descendant's path starts with ancestor's path
        return str_starts_with($descendant['path'], $ancestorLocation['path'] . '/');
    }
}
