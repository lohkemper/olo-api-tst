<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST handler for warehouse-locations endpoint
 * Handles: POST /api/warehouse-locations (create new location)
 *
 * @version 1.0.0
 */
class requestPostWarehouseLocations extends RequestBase {
    private array $data = [];

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            $this->log('requestPostWarehouseLocations::execute');
            $this->log(['requestPostWarehouseLocations::data', $this->data]);

            // Requires authentication
            $user = $this->getCurrentUser();
            if (!$user) {
                http_response_code(401);
                echo json_encode(['error' => 'Authentication required']);
                return;
            }

            $userId = (int)$user['users_id'];

            // Validate required fields
            if (empty($this->data['name'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Location name is required']);
                return;
            }

            // Validate parent exists (if provided) and belongs to user
            $parentId = $this->data['parent_id'] ?? null;
            if ($parentId !== null) {
                $parentId = (int)$parentId;

                $sql = "SELECT locations_id FROM " . PREFIX . "_warehouse_locations WHERE locations_id = ? AND user_id = ?";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([$parentId, $userId]);

                if (!$stmt->fetch()) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Parent location not found or access denied']);
                    return;
                }
            }

            // Insert location (triggers will calculate path and level)
            $sql = "
                INSERT INTO " . PREFIX . "_warehouse_locations
                (name, parent_id, type, description, meta,
                 grid_rows, grid_cols, width_cm, height_cm, depth_cm, user_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $this->data['name'],
                $parentId,
                $this->data['type'] ?? null,
                $this->data['description'] ?? null,
                isset($this->data['meta']) ? json_encode($this->data['meta']) : null,
                $this->nullableUint($this->data['grid_rows'] ?? null),
                $this->nullableUint($this->data['grid_cols'] ?? null),
                $this->nullableUint($this->data['width_cm']  ?? null),
                $this->nullableUint($this->data['height_cm'] ?? null),
                $this->nullableUint($this->data['depth_cm']  ?? null),
                $userId
            ]);

            $newLocationId = (int)$this->pdo->lastInsertId();

            // Fetch created location
            $sql = "
                SELECT *
                FROM " . PREFIX . "_warehouse_locations
                WHERE locations_id = ?
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$newLocationId]);
            $location = $stmt->fetch(PDO::FETCH_ASSOC);

            http_response_code(201);
            header('Content-Type: application/json');
            echo json_encode([$location]); // Array for consistency with update

        } catch (\Throwable $e) {
            $this->handleError('Error creating warehouse location', $e);
        }
    }

    /**
     * Normalisiert Eingabe zu UNSIGNED INT oder NULL.
     * Akzeptiert nur positive Ganzzahlen; alles andere wird zu NULL.
     */
    private function nullableUint(mixed $value): ?int {
        if ($value === null || $value === '' || $value === false) return null;
        if (!is_numeric($value)) return null;
        $i = (int)$value;
        return $i > 0 ? $i : null;
    }
}
