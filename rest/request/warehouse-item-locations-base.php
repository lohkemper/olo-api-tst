<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Gemeinsame Helfer für Item-Location-Splits (mbc_warehouse_item_locations).
 *
 * Modell:
 * - items.quantity bleibt der physische GESAMT-Bestand (Packlisten-Ledger
 *   unverändert: available = quantity - reserviert).
 * - Die Junction verteilt Teilmengen auf Lagerplätze.
 *   Invariante: SUM(junction.quantity) <= items.quantity; Rest = unassigned.
 * - items.location_id/grid_row/grid_col bleiben als denormalisierte
 *   Primär-Zuordnung erhalten (größte Menge, bei Gleichstand kleinste
 *   item_locations_id) und werden hier nachgeführt.
 *
 * @version 1.0.0
 */
final class WarehouseItemLocations {

    /**
     * Zuordnungen eines Items (größte Menge zuerst — Reihenfolge = Primär-Regel).
     * Defensiv: fehlt die Tabelle (Pre-Migration), leeres Array.
     */
    public static function fetchAssignments(PDO $pdo, int $itemId): array {
        try {
            $sql = "
                SELECT item_locations_id, location_id, quantity, grid_row, grid_col
                FROM " . PREFIX . "_warehouse_item_locations
                WHERE item_id = ?
                ORDER BY quantity DESC, item_locations_id ASC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$itemId]);
            return array_map([self::class, 'castRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Hängt an jedes Item ein 'locations'-Array (EINE Batch-Query, kein N+1).
     * Defensiv: fehlt die Tabelle (Pre-Migration), wird die Zuordnung aus
     * location_id/quantity des Items synthetisiert.
     */
    public static function enrichItemsWithLocations(PDO $pdo, array $items): array {
        if (empty($items)) return $items;

        $byItem = null;
        try {
            $ids = array_map(static fn(array $i): int => (int)$i['items_id'], $items);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "
                SELECT item_locations_id, item_id, location_id, quantity, grid_row, grid_col
                FROM " . PREFIX . "_warehouse_item_locations
                WHERE item_id IN ($placeholders)
                ORDER BY quantity DESC, item_locations_id ASC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($ids);
            $byItem = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $byItem[(int)$row['item_id']][] = self::castRow($row);
            }
        } catch (\Throwable $e) {
            $byItem = null; // Tabelle fehlt (Pre-Migration) → Fallback unten
        }

        foreach ($items as &$item) {
            if ($byItem !== null) {
                $item['locations'] = $byItem[(int)$item['items_id']] ?? [];
            } elseif (isset($item['location_id']) && $item['location_id'] !== null) {
                $item['locations'] = [[
                    'item_locations_id' => 0,
                    'location_id' => (int)$item['location_id'],
                    'quantity' => (float)($item['quantity'] ?? 1),
                    'grid_row' => isset($item['grid_row']) ? (int)$item['grid_row'] : null,
                    'grid_col' => isset($item['grid_col']) ? (int)$item['grid_col'] : null,
                ]];
            } else {
                $item['locations'] = [];
            }
        }
        unset($item);

        return $items;
    }

    /** Bequemlichkeits-Variante für Einzel-Items. */
    public static function enrichItemWithLocations(PDO $pdo, array $item): array {
        $enriched = self::enrichItemsWithLocations($pdo, [$item]);
        return $enriched[0];
    }

    /** Summe der bereits zugeordneten Teilmengen eines Items. */
    public static function assignedSum(PDO $pdo, int $itemId): float {
        try {
            $sql = "
                SELECT COALESCE(SUM(quantity), 0)
                FROM " . PREFIX . "_warehouse_item_locations
                WHERE item_id = ?
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$itemId]);
            return (float)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    /**
     * Führt items.location_id/grid_row/grid_col als Primär-Zuordnung nach:
     * Zuordnung mit größter Menge (bei Gleichstand kleinste item_locations_id),
     * NULL wenn keine Zuordnung existiert.
     */
    public static function recomputePrimaryLocation(PDO $pdo, int $itemId): void {
        $assignments = self::fetchAssignments($pdo, $itemId);
        $primary = $assignments[0] ?? null;

        $sql = "
            UPDATE " . PREFIX . "_warehouse_items
            SET location_id = ?, grid_row = ?, grid_col = ?
            WHERE items_id = ?
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $primary !== null ? $primary['location_id'] : null,
            $primary !== null ? $primary['grid_row'] : null,
            $primary !== null ? $primary['grid_col'] : null,
            $itemId,
        ]);
    }

    /** NULL-sicherer Slot-Vergleich einer Junction-Zeile mit (grid_row, grid_col). */
    public static function sameSlot(array $row, ?int $gridRow, ?int $gridCol): bool {
        $rowVal = isset($row['grid_row']) && $row['grid_row'] !== null ? (int)$row['grid_row'] : null;
        $colVal = isset($row['grid_col']) && $row['grid_col'] !== null ? (int)$row['grid_col'] : null;
        return $rowVal === $gridRow && $colVal === $gridCol;
    }

    /**
     * Merged eine Teilmenge in die Slot-Zeile (item, location, grid_row, grid_col)
     * bzw. legt sie an. Seit dem Grid-Slot-Ausbau (Schema 1.6.0) gibt es kein
     * DB-UNIQUE mehr — der NULL-sichere Slot-Vergleich (<=>) hier ist die
     * einzige Duplikat-Abwehr; alle Schreibpfade MÜSSEN über diesen Helfer gehen.
     */
    public static function upsertSlot(
        PDO $pdo,
        int $userId,
        int $itemId,
        int $locationId,
        float $quantity,
        ?int $gridRow,
        ?int $gridCol
    ): void {
        $sql = "
            UPDATE " . PREFIX . "_warehouse_item_locations
            SET quantity = quantity + ?
            WHERE item_id = ? AND location_id = ?
              AND grid_row <=> ? AND grid_col <=> ?
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$quantity, $itemId, $locationId, $gridRow, $gridCol]);

        if ($stmt->rowCount() === 0) {
            $sql = "
                INSERT INTO " . PREFIX . "_warehouse_item_locations
                    (user_id, item_id, location_id, quantity, grid_row, grid_col)
                VALUES (?, ?, ?, ?, ?, ?)
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId, $itemId, $locationId, $quantity, $gridRow, $gridCol]);
        }
    }

    /** Prüft, ob ALLE Locations existieren und dem User gehören. */
    public static function validateLocationOwnership(PDO $pdo, int $userId, array $locationIds): bool {
        $locationIds = array_values(array_unique(array_map('intval', $locationIds)));
        if (empty($locationIds)) return true;

        $placeholders = implode(',', array_fill(0, count($locationIds), '?'));
        $sql = "
            SELECT COUNT(*)
            FROM " . PREFIX . "_warehouse_locations
            WHERE user_id = ? AND locations_id IN ($placeholders)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$userId], $locationIds));
        return (int)$stmt->fetchColumn() === count($locationIds);
    }

    /**
     * Alt-Semantik: ersetzt ALLE Zuordnungen durch genau eine (volle Item-Menge
     * an $locationId) bzw. keine ($locationId = null). Junction-Teil defensiv
     * (Pre-Migration bleibt der Legacy-Pfad über items.location_id intakt).
     */
    public static function replaceAllWithSingle(
        PDO $pdo,
        int $userId,
        int $itemId,
        ?int $locationId,
        ?int $gridRow = null,
        ?int $gridCol = null
    ): void {
        try {
            $stmt = $pdo->prepare("DELETE FROM " . PREFIX . "_warehouse_item_locations WHERE item_id = ?");
            $stmt->execute([$itemId]);

            if ($locationId !== null) {
                $sql = "
                    INSERT INTO " . PREFIX . "_warehouse_item_locations
                        (user_id, item_id, location_id, quantity, grid_row, grid_col)
                    SELECT user_id, items_id, ?, quantity, ?, ?
                    FROM " . PREFIX . "_warehouse_items
                    WHERE items_id = ?
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$locationId, $gridRow, $gridCol, $itemId]);
            }
        } catch (\Throwable $e) {
            // Tabelle fehlt (Pre-Migration) → nur Legacy-Spalten pflegen
        }

        $sql = "
            UPDATE " . PREFIX . "_warehouse_items
            SET location_id = ?, grid_row = ?, grid_col = ?
            WHERE items_id = ? AND user_id = ?
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$locationId, $gridRow, $gridCol, $itemId, $userId]);
    }

    /** Typen der Wire-Repräsentation normalisieren (snake_case, quantity als float). */
    private static function castRow(array $row): array {
        return [
            'item_locations_id' => (int)$row['item_locations_id'],
            'location_id' => (int)$row['location_id'],
            'quantity' => (float)$row['quantity'],
            'grid_row' => $row['grid_row'] !== null ? (int)$row['grid_row'] : null,
            'grid_col' => $row['grid_col'] !== null ? (int)$row['grid_col'] : null,
        ];
    }
}
