<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST handler for warehouse-items endpoint
 * Handles: POST /api/warehouse-items (create new item)
 *
 * @version 1.0.0
 */
class requestPostWarehouseItems extends RequestBase {
    private array $data = [];

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            $this->log('requestPostWarehouseItems::execute');
            $this->log(['requestPostWarehouseItems::data', $this->data]);

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
                echo json_encode(['error' => 'Item name is required']);
                return;
            }

            // Validate location exists (if provided) and belongs to user
            $locationId = $this->data['location_id'] ?? null;
            if ($locationId !== null) {
                $locationId = (int)$locationId;

                $sql = "SELECT locations_id FROM " . PREFIX . "_warehouse_locations WHERE locations_id = ? AND user_id = ?";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([$locationId, $userId]);

                if (!$stmt->fetch()) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Location not found or access denied']);
                    return;
                }
            }

            // Insert item
            $sql = "
                INSERT INTO " . PREFIX . "_warehouse_items
                (name, description, location_id, quantity, unit, barcode, image_url, meta,
                 grid_row, grid_col, user_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $this->data['name'],
                $this->data['description'] ?? null,
                $locationId,
                $this->data['quantity'] ?? 1.0,
                $this->data['unit'] ?? 'Stück',
                $this->data['barcode'] ?? null,
                $this->data['image_url'] ?? null,
                isset($this->data['meta']) ? json_encode($this->data['meta']) : null,
                $this->nullableUint($this->data['grid_row'] ?? null),
                $this->nullableUint($this->data['grid_col'] ?? null),
                $userId
            ]);

            $newItemId = (int)$this->pdo->lastInsertId();

            // Teilmengen-Modell: Zuordnung mit voller Menge in der Junction spiegeln
            // (hält die Invariante SUM(Splits) <= quantity ab Erzeugung)
            if ($locationId !== null) {
                WarehouseItemLocations::replaceAllWithSingle(
                    $this->pdo,
                    $userId,
                    $newItemId,
                    $locationId,
                    $this->nullableUint($this->data['grid_row'] ?? null),
                    $this->nullableUint($this->data['grid_col'] ?? null)
                );
            }

            // Handle tags
            if (isset($this->data['tags']) && is_array($this->data['tags'])) {
                $this->updateItemTags($newItemId, $this->data['tags']);
            }

            // Fetch created item
            $sql = "SELECT * FROM " . PREFIX . "_warehouse_items WHERE items_id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$newItemId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            $enrichedItem = WarehouseItemLocations::enrichItemWithLocations(
                $this->pdo,
                $this->enrichItemWithTags($item)
            );

            http_response_code(201);
            header('Content-Type: application/json');
            echo json_encode([$enrichedItem]); // Array for consistency

        } catch (\Throwable $e) {
            $this->handleError('Error creating warehouse item', $e);
        }
    }

    /**
     * Update tags for an item
     * Tags format: [{"id": 1, "name": "Kabel"}, {"name": "Elektronik"}]
     */
    private function updateItemTags(int $itemId, array $tags): void {
        foreach ($tags as $tag) {
            $tagId = null;

            // If tag has ID, use it
            if (isset($tag['id'])) {
                $tagId = (int)$tag['id'];
            }
            // If tag has name, find or create it
            elseif (isset($tag['name'])) {
                $tagId = $this->findOrCreateTag($tag['name']);
            }

            if ($tagId) {
                // Insert into junction table (ignore duplicates)
                $sql = "
                    INSERT IGNORE INTO " . PREFIX . "_warehouse_item_tags (items_id, tag_id)
                    VALUES (?, ?)
                ";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([$itemId, $tagId]);
            }
        }
    }

    /**
     * Find tag by name or create if not exists
     */
    private function findOrCreateTag(string $tagName): int {
        // Try to find existing tag
        $sql = "SELECT tags_id FROM " . PREFIX . "_tags WHERE name = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tagName]);
        $tag = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($tag) {
            return (int)$tag['tags_id'];
        }

        // Create new tag
        $sql = "INSERT INTO " . PREFIX . "_tags (name) VALUES (?)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tagName]);

        return (int)$this->pdo->lastInsertId();
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
     * Enriches item with tags
     */
    private function enrichItemWithTags(array $item): array {
        $itemId = (int)$item['items_id'];

        $sql = "
            SELECT t.tags_id AS id, t.name
            FROM " . PREFIX . "_tags t
            INNER JOIN " . PREFIX . "_warehouse_item_tags it ON t.tags_id = it.tag_id
            WHERE it.items_id = ?
            ORDER BY t.name
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$itemId]);
        $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $item['tags'] = $tags;
        return $item;
    }
}
