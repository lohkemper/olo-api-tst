<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * PUT handler for warehouse-items endpoint
 * Handles:
 * - PUT /api/warehouse-items/{id} (update item)
 * - PUT /api/warehouse-items/{id}/assign (assign to location; mit optionalem
 *   quantity/source_location_id = Teilmenge verschieben/splitten)
 * - PUT /api/warehouse-items/{id}/unassign (remove assignment; mit optionalem
 *   location_id/quantity = nur einen Split (teilweise) zurück in den Rest)
 * - PUT /api/warehouse-items/{id}/locations (alle Zuordnungen atomar setzen)
 *
 * Teilmengen-Modell: siehe warehouse-item-locations-base.php.
 *
 * @version 1.1.0
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
                } elseif ($subroute === 'locations') {
                    $this->handleSetLocations($userId, $itemId);
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

        // location_id: array_key_exists, damit explizites null (Unassign über
        // das Edit-Formular) durchgeht. Wird NICHT über $updates geschrieben,
        // sondern unten via Junction-Replace + Legacy-Sync (Alt-Semantik:
        // eine Location fürs ganze Item ersetzt alle Splits).
        $locationProvided = array_key_exists('location_id', $_PUT);
        $newLocationId = null;

        if ($locationProvided && $_PUT['location_id'] !== null) {
            $newLocationId = (int)$_PUT['location_id'];

            if (!WarehouseItemLocations::validateLocationOwnership($this->pdo, $userId, [$newLocationId])) {
                http_response_code(400);
                echo json_encode(['error' => 'Location not found or access denied']);
                return;
            }
        }

        // Menge, die einer einzelnen Voll-Zuordnung folgen darf: hat das Item
        // genau einen Split über die komplette Menge (Standardfall), zieht der
        // Split bei Mengenänderung mit. Sonst gilt strikt SUM <= quantity (409).
        $followSingleAssignment = false;

        if (isset($_PUT['quantity'])) {
            if (!$locationProvided) {
                $newQuantity = (float)$_PUT['quantity'];
                $oldQuantity = (float)($existingItem['quantity'] ?? 0);
                $assignments = WarehouseItemLocations::fetchAssignments($this->pdo, $itemId);
                $assignedSum = array_sum(array_column($assignments, 'quantity'));

                $followSingleAssignment = count($assignments) === 1
                    && abs($assignedSum - $oldQuantity) < 1e-9;

                if (!$followSingleAssignment && $newQuantity + 1e-9 < $assignedSum) {
                    http_response_code(409);
                    echo json_encode([
                        'error' => 'Quantity below assigned total - reduce location assignments first',
                        'assigned_quantity' => $assignedSum,
                    ]);
                    return;
                }
            }

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

        // Junction-Sync (Teilmengen-Modell)
        if ($locationProvided) {
            // Alt-Semantik: ersetzt alle Splits durch eine Voll-Zuordnung (oder keine)
            WarehouseItemLocations::replaceAllWithSingle(
                $this->pdo,
                $userId,
                $itemId,
                $newLocationId,
                array_key_exists('grid_row', $_PUT) ? $this->nullableUint($_PUT['grid_row']) : null,
                array_key_exists('grid_col', $_PUT) ? $this->nullableUint($_PUT['grid_col']) : null
            );
        } else {
            try {
                if ($followSingleAssignment && isset($_PUT['quantity'])) {
                    // Einzige Voll-Zuordnung zieht bei Mengenänderung mit
                    $sql = "UPDATE " . PREFIX . "_warehouse_item_locations SET quantity = ? WHERE item_id = ?";
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->execute([$_PUT['quantity'], $itemId]);
                }

                if ((array_key_exists('grid_row', $_PUT) || array_key_exists('grid_col', $_PUT))
                    && $existingItem['location_id'] !== null
                ) {
                    // Grid-Änderung ohne Location-Wechsel → Primär-Zuordnung syncen
                    $sql = "
                        UPDATE " . PREFIX . "_warehouse_item_locations
                        SET grid_row = ?, grid_col = ?
                        WHERE item_id = ? AND location_id = ?
                    ";
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->execute([
                        array_key_exists('grid_row', $_PUT) ? $this->nullableUint($_PUT['grid_row']) : $this->nullableUint($existingItem['grid_row']),
                        array_key_exists('grid_col', $_PUT) ? $this->nullableUint($_PUT['grid_col']) : $this->nullableUint($existingItem['grid_col']),
                        $itemId,
                        (int)$existingItem['location_id'],
                    ]);
                }
            } catch (\Throwable $e) {
                // Tabelle fehlt (Pre-Migration) → Legacy-Spalten sind bereits gepflegt
            }
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

        $enrichedItem = $this->fetchEnrichedItem($itemId);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([$enrichedItem]);
    }

    /**
     * PUT /api/warehouse-items/{id}/assign - Assign item to location
     *
     * Body:
     * - { location_id }                 Alt-Semantik: ganzes Item dorthin
     *                                   (ersetzt alle Splits durch eine Voll-Zuordnung)
     * - { location_id, quantity }       Teilmenge aus dem unassigned-Rest dorthin
     * - { location_id, quantity,
     *     source_location_id }          Teilmenge von einem bestehenden Split dorthin
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

        if (!WarehouseItemLocations::validateLocationOwnership($this->pdo, $userId, [$locationId])) {
            http_response_code(400);
            echo json_encode(['error' => 'Location not found or access denied']);
            return;
        }

        $gridRow = array_key_exists('grid_row', $_PUT) ? $this->nullableUint($_PUT['grid_row']) : null;
        $gridCol = array_key_exists('grid_col', $_PUT) ? $this->nullableUint($_PUT['grid_col']) : null;

        if (!isset($_PUT['quantity'])) {
            // Alt-Semantik: ganzes Item an diese Location
            WarehouseItemLocations::replaceAllWithSingle($this->pdo, $userId, $itemId, $locationId, $gridRow, $gridCol);
            $this->respondWithItem($itemId);
            return;
        }

        // Teilmengen-Semantik
        $quantity = round((float)$_PUT['quantity'], 2);
        if ($quantity <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Quantity must be greater than 0']);
            return;
        }

        $sourceLocationId = isset($_PUT['source_location_id']) ? (int)$_PUT['source_location_id'] : null;

        $this->pdo->beginTransaction();
        try {
            // Item sperren, damit Rest-Berechnung und Upsert atomar sind
            $sql = "SELECT quantity FROM " . PREFIX . "_warehouse_items WHERE items_id = ? AND user_id = ? FOR UPDATE";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$itemId, $userId]);
            $totalQuantity = (float)$stmt->fetchColumn();

            if ($sourceLocationId !== null) {
                // Quelle: bestehender Split
                $sql = "
                    SELECT item_locations_id, quantity
                    FROM " . PREFIX . "_warehouse_item_locations
                    WHERE item_id = ? AND location_id = ?
                    FOR UPDATE
                ";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([$itemId, $sourceLocationId]);
                $source = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$source && (int)($item['location_id'] ?? 0) === $sourceLocationId) {
                    // Legacy-Fallback: Item hängt nur über items.location_id an der
                    // Quelle (Zuordnung entstand vor der Junction-Synchronisation,
                    // z.B. zwischen Backfill und Backend-Deploy) → Voll-Zuordnung
                    // on-the-fly spiegeln und damit normal weiterarbeiten.
                    $ins = $this->pdo->prepare("
                        INSERT INTO " . PREFIX . "_warehouse_item_locations
                            (user_id, item_id, location_id, quantity, grid_row, grid_col)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $ins->execute([
                        $userId,
                        $itemId,
                        $sourceLocationId,
                        $totalQuantity,
                        $this->nullableUint($item['grid_row'] ?? null),
                        $this->nullableUint($item['grid_col'] ?? null),
                    ]);
                    $source = [
                        'item_locations_id' => (int)$this->pdo->lastInsertId(),
                        'quantity' => $totalQuantity,
                    ];
                }

                if (!$source) {
                    $this->pdo->rollBack();
                    http_response_code(400);
                    echo json_encode(['error' => 'Source location has no assignment for this item']);
                    return;
                }

                $sourceQuantity = (float)$source['quantity'];
                if ($sourceQuantity + 1e-9 < $quantity) {
                    $this->pdo->rollBack();
                    http_response_code(409);
                    echo json_encode([
                        'error' => 'Insufficient quantity at source location',
                        'available_quantity' => $sourceQuantity,
                    ]);
                    return;
                }

                if (abs($sourceQuantity - $quantity) < 1e-9) {
                    $sql = "DELETE FROM " . PREFIX . "_warehouse_item_locations WHERE item_locations_id = ?";
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->execute([(int)$source['item_locations_id']]);
                } else {
                    $sql = "UPDATE " . PREFIX . "_warehouse_item_locations SET quantity = quantity - ? WHERE item_locations_id = ?";
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->execute([$quantity, (int)$source['item_locations_id']]);
                }
            } else {
                // Quelle: unassigned-Rest
                $rest = $totalQuantity - WarehouseItemLocations::assignedSum($this->pdo, $itemId);
                if ($rest + 1e-9 < $quantity) {
                    $this->pdo->rollBack();
                    http_response_code(409);
                    echo json_encode([
                        'error' => 'Insufficient unassigned quantity',
                        'unassigned_quantity' => max(0, $rest),
                    ]);
                    return;
                }
            }

            // Ziel-Upsert: bestehende Zuordnung an der Ziel-Location wird gemergt
            $sql = "
                INSERT INTO " . PREFIX . "_warehouse_item_locations
                    (user_id, item_id, location_id, quantity, grid_row, grid_col)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$userId, $itemId, $locationId, $quantity, $gridRow, $gridCol]);

            WarehouseItemLocations::recomputePrimaryLocation($this->pdo, $itemId);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->respondWithItem($itemId);
    }

    /**
     * PUT /api/warehouse-items/{id}/unassign - Remove item assignment
     *
     * Body:
     * - {}                              Alt-Semantik: alle Zuordnungen entfernen
     * - { location_id }                 nur diesen Split komplett in den Rest
     * - { location_id, quantity }       Teilmenge dieses Splits in den Rest
     */
    private function handleUnassignItem(int $userId, int $itemId): void {
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
            // Alt-Semantik: alle Zuordnungen entfernen
            WarehouseItemLocations::replaceAllWithSingle($this->pdo, $userId, $itemId, null);
            $this->respondWithItem($itemId);
            return;
        }

        $locationId = (int)$_PUT['location_id'];

        $sql = "
            SELECT item_locations_id, quantity
            FROM " . PREFIX . "_warehouse_item_locations
            WHERE item_id = ? AND location_id = ?
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$itemId, $locationId]);
        $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$assignment && (int)($item['location_id'] ?? 0) === $locationId) {
            // Legacy-Fallback: Item hängt nur über items.location_id an dieser
            // Location (keine Junction-Zeile) → Voll-Zuordnung on-the-fly
            // spiegeln, damit auch Teil-Unassigns funktionieren.
            $ins = $this->pdo->prepare("
                INSERT INTO " . PREFIX . "_warehouse_item_locations
                    (user_id, item_id, location_id, quantity, grid_row, grid_col)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([
                $userId,
                $itemId,
                $locationId,
                (float)($item['quantity'] ?? 1),
                $this->nullableUint($item['grid_row'] ?? null),
                $this->nullableUint($item['grid_col'] ?? null),
            ]);
            $assignment = [
                'item_locations_id' => (int)$this->pdo->lastInsertId(),
                'quantity' => (float)($item['quantity'] ?? 1),
            ];
        }

        if (!$assignment) {
            http_response_code(400);
            echo json_encode(['error' => 'Location has no assignment for this item']);
            return;
        }

        $assignmentQuantity = (float)$assignment['quantity'];
        $quantity = isset($_PUT['quantity']) ? round((float)$_PUT['quantity'], 2) : $assignmentQuantity;

        if ($quantity <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Quantity must be greater than 0']);
            return;
        }

        if ($assignmentQuantity + 1e-9 < $quantity) {
            http_response_code(409);
            echo json_encode([
                'error' => 'Insufficient quantity at location',
                'available_quantity' => $assignmentQuantity,
            ]);
            return;
        }

        if (abs($assignmentQuantity - $quantity) < 1e-9) {
            $sql = "DELETE FROM " . PREFIX . "_warehouse_item_locations WHERE item_locations_id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([(int)$assignment['item_locations_id']]);
        } else {
            $sql = "UPDATE " . PREFIX . "_warehouse_item_locations SET quantity = quantity - ? WHERE item_locations_id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$quantity, (int)$assignment['item_locations_id']]);
        }

        WarehouseItemLocations::recomputePrimaryLocation($this->pdo, $itemId);

        $this->respondWithItem($itemId);
    }

    /**
     * PUT /api/warehouse-items/{id}/locations - Alle Zuordnungen atomar setzen
     *
     * Body: { locations: [ { location_id, quantity, grid_row?, grid_col? }, ... ] }
     * Validierung: Ownership, keine Duplikate, quantity > 0, SUM <= item.quantity.
     */
    private function handleSetLocations(int $userId, int $itemId): void {
        global $_PUT;

        if (!isset($_PUT['locations']) || !is_array($_PUT['locations'])) {
            http_response_code(400);
            echo json_encode(['error' => 'locations array is required']);
            return;
        }

        // Eingabe normalisieren + validieren (vor der Transaktion)
        $entries = [];
        $locationIds = [];
        $sum = 0.0;

        foreach ($_PUT['locations'] as $entry) {
            if (!is_array($entry) || !isset($entry['location_id']) || !isset($entry['quantity'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Each entry requires location_id and quantity']);
                return;
            }

            $locationId = (int)$entry['location_id'];
            $quantity = round((float)$entry['quantity'], 2);

            if ($quantity <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Quantity must be greater than 0']);
                return;
            }

            if (in_array($locationId, $locationIds, true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Duplicate location_id in locations array']);
                return;
            }

            $locationIds[] = $locationId;
            $sum += $quantity;
            $entries[] = [
                'location_id' => $locationId,
                'quantity' => $quantity,
                'grid_row' => array_key_exists('grid_row', $entry) ? $this->nullableUint($entry['grid_row']) : null,
                'grid_col' => array_key_exists('grid_col', $entry) ? $this->nullableUint($entry['grid_col']) : null,
            ];
        }

        if (!WarehouseItemLocations::validateLocationOwnership($this->pdo, $userId, $locationIds)) {
            http_response_code(400);
            echo json_encode(['error' => 'Location not found or access denied']);
            return;
        }

        $this->pdo->beginTransaction();
        try {
            $sql = "SELECT quantity FROM " . PREFIX . "_warehouse_items WHERE items_id = ? AND user_id = ? FOR UPDATE";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$itemId, $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $this->pdo->rollBack();
                http_response_code(404);
                echo json_encode(['error' => 'Item not found']);
                return;
            }

            $totalQuantity = (float)$row['quantity'];
            if ($sum > $totalQuantity + 1e-9) {
                $this->pdo->rollBack();
                http_response_code(409);
                echo json_encode([
                    'error' => 'Assigned total exceeds item quantity',
                    'item_quantity' => $totalQuantity,
                    'assigned_quantity' => $sum,
                ]);
                return;
            }

            $stmt = $this->pdo->prepare("DELETE FROM " . PREFIX . "_warehouse_item_locations WHERE item_id = ?");
            $stmt->execute([$itemId]);

            $sql = "
                INSERT INTO " . PREFIX . "_warehouse_item_locations
                    (user_id, item_id, location_id, quantity, grid_row, grid_col)
                VALUES (?, ?, ?, ?, ?, ?)
            ";
            $stmt = $this->pdo->prepare($sql);
            foreach ($entries as $entry) {
                $stmt->execute([
                    $userId,
                    $itemId,
                    $entry['location_id'],
                    $entry['quantity'],
                    $entry['grid_row'],
                    $entry['grid_col'],
                ]);
            }

            WarehouseItemLocations::recomputePrimaryLocation($this->pdo, $itemId);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->respondWithItem($itemId);
    }

    /**
     * Antwortet mit dem frisch geladenen, angereicherten Item als NACKTES Objekt
     * (Konvention der assign/unassign/locations-Subroutes).
     */
    private function respondWithItem(int $itemId): void {
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($this->fetchEnrichedItem($itemId));
    }

    /** Item frisch laden und um Tags + Location-Splits anreichern. */
    private function fetchEnrichedItem(int $itemId): array {
        $sql = "SELECT * FROM " . PREFIX . "_warehouse_items WHERE items_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$itemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        return WarehouseItemLocations::enrichItemWithLocations($this->pdo, $this->enrichItemWithTags($item));
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
