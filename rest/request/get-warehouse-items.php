<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET handler for warehouse-items endpoint
 * Handles:
 * - GET /api/warehouse-items (all items for user)
 * - GET /api/warehouse-items/{id} (specific item)
 * - GET /api/warehouse-items/{id}/locations (Location-Splits des Items)
 * - GET /api/warehouse-items/search?q=query (search items)
 * - GET /api/warehouse-items/by-tag?tag=tagname (items by tag)
 *
 * Alle Item-Responses enthalten ein 'locations'-Array (Teilmengen-Splits,
 * siehe warehouse-item-locations-base.php).
 *
 * @version 1.1.0
 */
class requestGetWarehouseItems extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            $this->log('requestGetWarehouseItems::execute');
            $this->log(['requestGetWarehouseItems::request', $this->request]);

            // Requires authentication
            $user = $this->getCurrentUser();
            if (!$user) {
                http_response_code(401);
                echo json_encode(['error' => 'Authentication required']);
                return;
            }

            $userId = (int)$user['users_id'];

            // Route to appropriate handler
            if (isset($this->request['id'])) {
                $itemId = is_array($this->request['id'])
                    ? (int)$this->request['id'][0]
                    : (int)$this->request['id'];

                // /{id}/locations VOR dem reinen id-Zweig prüfen
                if (isset($this->request['subroute'])) {
                    if ($this->request['subroute'] === 'locations') {
                        $this->handleGetItemLocations($userId, $itemId);
                    } else {
                        http_response_code(404);
                        echo json_encode(['error' => 'Unknown subroute']);
                    }
                    return;
                }

                $this->handleGetItem($userId, $itemId);
            } elseif (isset($this->request['subroute'])) {
                $subroute = $this->request['subroute'];

                if ($subroute === 'search') {
                    $this->handleSearchItems($userId);
                } elseif ($subroute === 'by-tag') {
                    $this->handleGetItemsByTag($userId);
                } else {
                    http_response_code(404);
                    echo json_encode(['error' => 'Unknown subroute']);
                }
            } else {
                $this->handleGetItems($userId);
            }

        } catch (\Throwable $e) {
            $this->handleError('Error executing GET warehouse-items request', $e);
        }
    }

    /**
     * GET /api/warehouse-items - All items for user
     */
    private function handleGetItems(int $userId): void {
        $sql = "
            SELECT *
            FROM " . PREFIX . "_warehouse_items
            WHERE user_id = ?
        ";

        $params = [$userId];

        // Filter by location_id if provided
        if (isset($this->request['location_id'])) {
            if ($this->request['location_id'] === 'null' || $this->request['location_id'] === '') {
                $sql .= " AND location_id IS NULL";
            } else {
                // Primär-Location ODER beliebiger Teilmengen-Split an dieser Location
                $sql .= " AND (location_id = ? OR EXISTS (
                    SELECT 1 FROM " . PREFIX . "_warehouse_item_locations il
                    WHERE il.item_id = " . PREFIX . "_warehouse_items.items_id
                      AND il.location_id = ?
                ))";
                $params[] = (int)$this->request['location_id'];
                $params[] = (int)$this->request['location_id'];
            }
        }

        $sql .= " ORDER BY name ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Enrich with tags
        $enrichedItems = array_map([$this, 'enrichItemWithTags'], $items);
        $enrichedItems = WarehouseItemLocations::enrichItemsWithLocations($this->pdo, $enrichedItems);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($enrichedItems);
    }

    /**
     * GET /api/warehouse-items/{id} - Single item
     */
    private function handleGetItem(int $userId, int $itemId): void {
        $sql = "
            SELECT *
            FROM " . PREFIX . "_warehouse_items
            WHERE items_id = ? AND user_id = ?
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$itemId, $userId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            http_response_code(404);
            echo json_encode(['error' => 'Item not found']);
            return;
        }

        $enrichedItem = WarehouseItemLocations::enrichItemWithLocations(
            $this->pdo,
            $this->enrichItemWithTags($item)
        );

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([$enrichedItem]);
    }

    /**
     * GET /api/warehouse-items/{id}/locations - Location-Splits des Items
     */
    private function handleGetItemLocations(int $userId, int $itemId): void {
        $sql = "SELECT items_id FROM " . PREFIX . "_warehouse_items WHERE items_id = ? AND user_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$itemId, $userId]);

        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Item not found']);
            return;
        }

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(WarehouseItemLocations::fetchAssignments($this->pdo, $itemId));
    }

    /**
     * GET /api/warehouse-items/search?q=query - Search items
     */
    private function handleSearchItems(int $userId): void {
        if (!isset($this->request['q'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Search query (q) is required']);
            return;
        }

        $query = '%' . $this->request['q'] . '%';

        $sql = "
            SELECT *
            FROM " . PREFIX . "_warehouse_items
            WHERE user_id = ?
              AND (
                name LIKE ? OR
                description LIKE ? OR
                barcode LIKE ?
              )
            ORDER BY name ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId, $query, $query, $query]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $enrichedItems = array_map([$this, 'enrichItemWithTags'], $items);
        $enrichedItems = WarehouseItemLocations::enrichItemsWithLocations($this->pdo, $enrichedItems);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($enrichedItems);
    }

    /**
     * GET /api/warehouse-items/by-tag?tag=tagname - Items by tag
     */
    private function handleGetItemsByTag(int $userId): void {
        if (!isset($this->request['tag'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Tag name is required']);
            return;
        }

        $tagName = $this->request['tag'];

        $sql = "
            SELECT i.*
            FROM " . PREFIX . "_warehouse_items i
            INNER JOIN " . PREFIX . "_warehouse_item_tags it ON i.items_id = it.items_id
            INNER JOIN " . PREFIX . "_tags t ON it.tag_id = t.tags_id
            WHERE i.user_id = ? AND t.name = ?
            ORDER BY i.name ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId, $tagName]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $enrichedItems = array_map([$this, 'enrichItemWithTags'], $items);
        $enrichedItems = WarehouseItemLocations::enrichItemsWithLocations($this->pdo, $enrichedItems);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($enrichedItems);
    }

    /**
     * Enriches item with tags from junction table
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

        return $this->enrichItemWithAvailability($item);
    }

    /**
     * Reichert das Item um reserved_quantity und available_quantity an.
     *
     * Reservierungs-Ledger: eine aktive Reservierung ist eine Packlisten-Position
     * mit checked_out = 1 AND returned = 0. available = quantity - reserved.
     * quantity (physischer Bestand) bleibt unverändert.
     *
     * Defensiv: fehlt die packlist_items-Tabelle (Migration 38 noch nicht
     * eingespielt), gilt reserved = 0 und der Items-Endpoint bleibt funktionsfähig.
     */
    private function enrichItemWithAvailability(array $item): array {
        $quantity = (float)($item['quantity'] ?? 0);
        $reserved = 0.0;

        try {
            $sql = "
                SELECT COALESCE(SUM(quantity), 0) AS reserved
                FROM " . PREFIX . "_warehouse_packlist_items
                WHERE item_id = ? AND checked_out = 1 AND returned = 0
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([(int)$item['items_id']]);
            $reserved = (float)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            // Tabelle fehlt (Pre-Migration) → reserved bleibt 0
            $reserved = 0.0;
        }

        $item['reserved_quantity'] = $reserved;
        $item['available_quantity'] = $quantity - $reserved;
        return $item;
    }
}
