<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * PUT handler for warehouse-items endpoint
 * Handles:
 * - PUT /api/warehouse-items/{id} (update item)
 * - PUT /api/warehouse-items/{id}/assign (assign to location)
 * - PUT /api/warehouse-items/{id}/unassign (remove assignment)
 *
 * @version 1.0.0
 */
class requestPutWarehouseItems extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            global $_PUT;
            $this->log('requestPutWarehouseItems::execute');
            $this->log(['requestPutWarehouseItems::request', $this->request]);
            $this->log(['requestPutWarehouseItems::_PUT', $_PUT]);

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
                echo json_encode(['error' => 'Item ID is required']);
                return;
            }

            $itemId = is_array($this->request['id'])
                ? (int)$this->request['id'][0]
                : (int)$this->request['id'];

            // Check subroute
            if (isset($this->request['subroute'])) {
                $subroute = $this->request['subroute'];

                if ($subroute === 'assign') {
                    $this->handleAssignItem($userId, $itemId);
                } elseif ($subroute === 'unassign') {
                    $this->handleUnassignItem($userId, $itemId);
                } else {
                    http_response_code(404);
                    echo json_encode(['error' => 'Unknown subroute']);
                }
            } else {
                $this->handleUpdateItem($userId, $itemId);
            }

        } catch (\Throwable $e) {
            $this->handleError('Error updating warehouse item', $e);
        }
    }

    /**
     * PUT /api/warehouse-items/{id} - Update item
     */
    private function handleUpdateItem(int $userId, int $itemId): void {
        global $_PUT;

        // Verify item exists and belongs to user
        $sql = "SELECT * FROM " . PREFIX . "_warehouse_items WHERE items_id = ? AND user_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$itemId, $userId]);
        $existingItem = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existingItem) {
            http_response_code(404);
            echo json_encode(['error' => 'Item not found']);
            return;
        }

        // Build update SQL dynamically
        $updates = [];
        $params = [];

        if (isset($_PUT['name'])) {
            $updates[] = "name = ?";
            $params[] = $_PUT['name'];
        }

        if (isset($_PUT['description'])) {
            $updates[] = "description = ?";
            $params[] = $_PUT['description'];
        }

        if (isset($_PUT['location_id'])) {
            $locationId = $_PUT['location_id'];

            if ($locationId !== null) {
                // Validate location exists and belongs to user
                $sql = "SELECT locations_id FROM " . PREFIX . "_warehouse_locations WHERE locations_id = ? AND user_id = ?";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([$locationId, $userId]);

                if (!$stmt->fetch()) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Location not found or access denied']);
                    return;
                }
            }

            $updates[] = "location_id = ?";
            $params[] = $locationId;
        }

        if (isset($_PUT['quantity'])) {
            $updates[] = "quantity = ?";
            $params[] = $_PUT['quantity'];
        }

        if (isset($_PUT['unit'])) {
            $updates[] = "unit = ?";
            $params[] = $_PUT['unit'];
        }

        if (isset($_PUT['barcode'])) {
            $updates[] = "barcode = ?";
            $params[] = $_PUT['barcode'];
        }

        if (isset($_PUT['image_url'])) {
            $updates[] = "image_url = ?";
            $params[] = $_PUT['image_url'];
        }

        if (isset($_PUT['meta'])) {
            $updates[] = "meta = ?";
            $params[] = json_encode($_PUT['meta']);
        }

        // Position im Lagerplatz-Raster — nullable, array_key_exists damit
        // explizites null (Position löschen) durchgeht.
        foreach (['grid_row', 'grid_col'] as $col) {
            if (array_key_exists($col, $_PUT)) {
                $updates[] = "$col = ?";
                $params[] = $this->nullableUint($_PUT[$col]);
            }
        }

        if (!empty($updates)) {
            $params[] = $itemId;

            $sql = "
                UPDATE " . PREFIX . "_warehouse_items
                SET " . implode(', ', $updates) . "
                WHERE items_id = ?
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
        }

        // Update tags if provided
        if (isset($_PUT['tags']) && is_array($_PUT['tags'])) {
            // Remove existing tags
            $sql = "DELETE FROM " . PREFIX . "_warehouse_item_tags WHERE items_id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$itemId]);

            // Add new tags
            $this->updateItemTags($itemId, $_PUT['tags']);
        }

        // Fetch updated item
        $sql = "SELECT * FROM " . PREFIX . "_warehouse_items WHERE items_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$itemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        $enrichedItem = $this->enrichItemWithTags($item);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([$enrichedItem]);
    }

    /**
     * PUT /api/warehouse-items/{id}/assign - Assign item to location
     */
    private function handleAssignItem(int $userId, int $itemId): void {
        global $_PUT;

        // Verify item exists and belongs to user
        $sql = "SELECT * FROM " . PREFIX . "_warehouse_items WHERE items_id = ? AND user_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$itemId, $userId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            http_response_code(404);
            echo json_encode(['error' => 'Item not found']);
            return;
        }

        if (!isset($_PUT['location_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Location ID is required']);
            return;
        }

        $locationId = (int)$_PUT['location_id'];

        // Validate location exists and belongs to user
        $sql = "SELECT locations_id FROM " . PREFIX . "_warehouse_locations WHERE locations_id = ? AND user_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$locationId, $userId]);

        if (!$stmt->fetch()) {
            http_response_code(400);
            echo json_encode(['error' => 'Location not found or access denied']);
            return;
        }

        // Update item
        $sql = "UPDATE " . PREFIX . "_warehouse_items SET location_id = ? WHERE items_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$locationId, $itemId]);

        // Fetch updated item
        $sql = "SELECT * FROM " . PREFIX . "_warehouse_items WHERE items_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$itemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        $enrichedItem = $this->enrichItemWithTags($item);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($enrichedItem);
    }

    /**
     * PUT /api/warehouse-items/{id}/unassign - Remove item assignment
     */
    private function handleUnassignItem(int $userId, int $itemId): void {
        // Verify item exists and belongs to user
        $sql = "SELECT * FROM " . PREFIX . "_warehouse_items WHERE items_id = ? AND user_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$itemId, $userId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            http_response_code(404);
            echo json_encode(['error' => 'Item not found']);
            return;
        }

        // Update item (set location_id to NULL)
        $sql = "UPDATE " . PREFIX . "_warehouse_items SET location_id = NULL WHERE items_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$itemId]);

        // Fetch updated item
        $sql = "SELECT * FROM " . PREFIX . "_warehouse_items WHERE items_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$itemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        $enrichedItem = $this->enrichItemWithTags($item);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($enrichedItem);
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
     * Update tags for an item
     */
    private function updateItemTags(int $itemId, array $tags): void {
        foreach ($tags as $tag) {
            $tagId = null;

            if (isset($tag['id'])) {
                $tagId = (int)$tag['id'];
            } elseif (isset($tag['name'])) {
                $tagId = $this->findOrCreateTag($tag['name']);
            }

            if ($tagId) {
                $sql = "INSERT IGNORE INTO " . PREFIX . "_warehouse_item_tags (items_id, tag_id) VALUES (?, ?)";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([$itemId, $tagId]);
            }
        }
    }

    /**
     * Find tag by name or create if not exists
     */
    private function findOrCreateTag(string $tagName): int {
        $sql = "SELECT tags_id FROM " . PREFIX . "_tags WHERE name = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tagName]);
        $tag = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($tag) {
            return (int)$tag['tags_id'];
        }

        $sql = "INSERT INTO " . PREFIX . "_tags (name) VALUES (?)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tagName]);

        return (int)$this->pdo->lastInsertId();
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
