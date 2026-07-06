<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Gemeinsame Basis für die Packlisten-/Verleih-Handler.
 *
 * Bündelt die Detail-Aufbau-Queries (nested groups/items bzw. positions),
 * den Kinder-Sync (delete + re-insert) sowie die Reservierungs-Aggregation
 * (Ledger: available = quantity − SUM aktive Reservierungen).
 *
 * @version 1.0.0
 */
abstract class WarehousePacklistBase extends RequestBase {

    // =====================================================================
    // Reservierungs-Ledger
    // =====================================================================

    /** Aktive Reservierung (checked_out=1, returned=0) für einen Artikel. */
    protected function reservedFor(int $itemId): float {
        $sql = "
            SELECT COALESCE(SUM(quantity), 0)
            FROM " . PREFIX . "_warehouse_packlist_items
            WHERE item_id = ? AND checked_out = 1 AND returned = 0
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$itemId]);
        return (float)$stmt->fetchColumn();
    }

    // =====================================================================
    // Templates
    // =====================================================================

    /** Vorlage laden + Ownership prüfen. */
    protected function findTemplate(int $templateId, int $userId): ?array {
        $sql = "
            SELECT templates_id, user_id, name, description, meta, created_at, updated_at
            FROM " . PREFIX . "_warehouse_packlist_templates
            WHERE templates_id = ? AND user_id = ?
            LIMIT 1
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$templateId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Vorlage-Detail inkl. groups + items (mit Live-Artikeldaten). */
    protected function buildTemplateDetail(int $templateId, int $userId): ?array {
        $template = $this->findTemplate($templateId, $userId);
        if (!$template) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT groups_id, name, selection_mode, order_index
             FROM " . PREFIX . "_warehouse_packlist_template_groups
             WHERE template_id = ? AND user_id = ?
             ORDER BY order_index ASC, groups_id ASC"
        );
        $stmt->execute([$templateId, $userId]);
        $template['groups'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $this->pdo->prepare(
            "SELECT ti.template_items_id, ti.group_id, ti.item_id, ti.quantity, ti.order_index,
                    i.name AS item_name, i.unit AS item_unit, i.quantity AS item_quantity,
                    i.location_id AS item_location_id,
                    COALESCE((
                      SELECT SUM(pli.quantity)
                      FROM " . PREFIX . "_warehouse_packlist_items pli
                      WHERE pli.item_id = ti.item_id AND pli.checked_out = 1 AND pli.returned = 0
                    ), 0) AS item_reserved
             FROM " . PREFIX . "_warehouse_packlist_template_items ti
             INNER JOIN " . PREFIX . "_warehouse_items i ON i.items_id = ti.item_id
             WHERE ti.template_id = ? AND ti.user_id = ?
             ORDER BY ti.order_index ASC, ti.template_items_id ASC"
        );
        $stmt->execute([$templateId, $userId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as &$it) {
            $it['item_available'] = (float)$it['item_quantity'] - (float)$it['item_reserved'];
        }
        unset($it);
        $template['items'] = $items;

        return $template;
    }

    /**
     * Synchronisiert Gruppen + Positionen einer Vorlage (delete + re-insert).
     *
     * $groups: [{ key?, name, selection_mode?, order_index? }]
     * $items:  [{ item_id, quantity?, order_index?, group_key? }]
     * Positionen verlinken über group_key auf ein Element aus $groups (key).
     * Nur Positionen mit gültigem, dem User gehörenden item_id werden übernommen.
     */
    protected function syncTemplateChildren(int $templateId, int $userId, ?array $groups, ?array $items): void {
        // Bestehende Kinder entfernen (items zuerst wegen FK auf groups)
        $stmt = $this->pdo->prepare(
            "DELETE FROM " . PREFIX . "_warehouse_packlist_template_items WHERE template_id = ? AND user_id = ?"
        );
        $stmt->execute([$templateId, $userId]);
        $stmt = $this->pdo->prepare(
            "DELETE FROM " . PREFIX . "_warehouse_packlist_template_groups WHERE template_id = ? AND user_id = ?"
        );
        $stmt->execute([$templateId, $userId]);

        // Gruppen einfügen, key → neue groups_id merken
        $keyMap = [];
        if (is_array($groups)) {
            $insGroup = $this->pdo->prepare(
                "INSERT INTO " . PREFIX . "_warehouse_packlist_template_groups
                   (user_id, template_id, name, selection_mode, order_index)
                 VALUES (?, ?, ?, ?, ?)"
            );
            foreach (array_values($groups) as $idx => $g) {
                if (!isset($g['name']) || $g['name'] === '') continue;
                $mode = (isset($g['selection_mode']) && $g['selection_mode'] === 'single') ? 'single' : 'multi';
                $insGroup->execute([
                    $userId, $templateId, $g['name'], $mode,
                    (int)($g['order_index'] ?? $idx),
                ]);
                $newId = (int)$this->pdo->lastInsertId();
                $key = (string)($g['key'] ?? $g['groups_id'] ?? $idx);
                $keyMap[$key] = $newId;
            }
        }

        // Positionen einfügen
        if (is_array($items)) {
            $insItem = $this->pdo->prepare(
                "INSERT INTO " . PREFIX . "_warehouse_packlist_template_items
                   (user_id, template_id, group_id, item_id, quantity, order_index)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            foreach (array_values($items) as $idx => $it) {
                $itemId = (int)($it['item_id'] ?? 0);
                if ($itemId <= 0 || !$this->userOwnsItem($itemId, $userId)) continue;

                $groupId = null;
                if (isset($it['group_key']) && $it['group_key'] !== null && $it['group_key'] !== '') {
                    $groupId = $keyMap[(string)$it['group_key']] ?? null;
                }

                $insItem->execute([
                    $userId, $templateId, $groupId, $itemId,
                    $this->normalizeQty($it['quantity'] ?? 1),
                    (int)($it['order_index'] ?? $idx),
                ]);
            }
        }
    }

    // =====================================================================
    // Packlists
    // =====================================================================

    /** Packliste laden + Ownership prüfen. */
    protected function findPacklist(int $packlistId, int $userId): ?array {
        $sql = "
            SELECT packlists_id, user_id, template_id, name, status,
                   borrower_name, borrower_contact, borrowed_at, due_at, returned_at,
                   notes, meta, created_at, updated_at
            FROM " . PREFIX . "_warehouse_packlists
            WHERE packlists_id = ? AND user_id = ?
            LIMIT 1
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$packlistId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Packlisten-Detail inkl. Positionen. Jede Position trägt:
     *  - Live-Artikeldaten (Name, Einheit, Bestand, aktueller Lagerort-Pfad)
     *  - Verfügbarkeit (available = Bestand − andere aktive Reservierungen)
     *  - source_location_path (Snapshot-Lagerort für die Rückgabe)
     */
    protected function buildPacklistDetail(int $packlistId, int $userId): ?array {
        $packlist = $this->findPacklist($packlistId, $userId);
        if (!$packlist) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT p.packlist_items_id, p.item_id, p.item_name, p.group_label,
                    p.quantity_planned, p.quantity, p.checked_out, p.checked_out_at,
                    p.returned, p.returned_at, p.source_location_id, p.order_index,
                    i.name AS live_item_name, i.unit AS item_unit, i.quantity AS item_quantity,
                    i.location_id AS item_location_id,
                    cur.name AS item_location_name, cur.path AS item_location_path,
                    src.name AS source_location_name, src.path AS source_location_path,
                    COALESCE((
                      SELECT SUM(o.quantity)
                      FROM " . PREFIX . "_warehouse_packlist_items o
                      WHERE o.item_id = p.item_id AND o.checked_out = 1 AND o.returned = 0
                        AND o.packlist_items_id <> p.packlist_items_id
                    ), 0) AS item_reserved_other
             FROM " . PREFIX . "_warehouse_packlist_items p
             LEFT JOIN " . PREFIX . "_warehouse_items i ON i.items_id = p.item_id
             LEFT JOIN " . PREFIX . "_warehouse_locations cur ON cur.locations_id = i.location_id
             LEFT JOIN " . PREFIX . "_warehouse_locations src ON src.locations_id = p.source_location_id
             WHERE p.packlist_id = ? AND p.user_id = ?
             ORDER BY p.order_index ASC, p.packlist_items_id ASC"
        );
        $stmt->execute([$packlistId, $userId]);
        $positions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($positions as &$pos) {
            // Verfügbar für DIESE Position = Bestand − Reservierungen ANDERER aktiver Positionen
            $stock = $pos['item_quantity'] !== null ? (float)$pos['item_quantity'] : 0.0;
            $pos['item_available'] = $stock - (float)$pos['item_reserved_other'];
            // Anzeigename: Live-Name bevorzugt, sonst Snapshot
            $pos['display_name'] = $pos['live_item_name'] ?? $pos['item_name'];
        }
        unset($pos);

        $packlist['positions'] = $positions;
        return $packlist;
    }

    /**
     * Legt Positionen für eine Packliste aus einer Vorlage an.
     *
     * $selections: { "<groups_id>": [itemId, ...] } — je Gruppe gewählte Optionen.
     * $quantities: { "<template_items_id>": qty }   — optionale Mengen-Overrides.
     *
     * Standalone-Positionen (group_id NULL) werden immer übernommen. Gruppierte
     * Positionen nur, wenn ihr item_id in der Auswahl der Gruppe enthalten ist.
     */
    protected function instantiateFromTemplate(int $packlistId, int $templateId, int $userId, array $selections, array $quantities): void {
        $stmt = $this->pdo->prepare(
            "SELECT ti.template_items_id, ti.group_id, ti.item_id, ti.quantity, ti.order_index,
                    g.name AS group_name,
                    i.name AS item_name
             FROM " . PREFIX . "_warehouse_packlist_template_items ti
             INNER JOIN " . PREFIX . "_warehouse_items i ON i.items_id = ti.item_id
             LEFT JOIN " . PREFIX . "_warehouse_packlist_template_groups g ON g.groups_id = ti.group_id
             WHERE ti.template_id = ? AND ti.user_id = ?
             ORDER BY ti.order_index ASC, ti.template_items_id ASC"
        );
        $stmt->execute([$templateId, $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $ins = $this->pdo->prepare(
            "INSERT INTO " . PREFIX . "_warehouse_packlist_items
               (user_id, packlist_id, item_id, item_name, group_label,
                quantity_planned, quantity, order_index)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        foreach ($rows as $idx => $r) {
            $groupId = $r['group_id'];
            if ($groupId !== null) {
                $chosen = $selections[(string)$groupId] ?? [];
                if (!is_array($chosen)) $chosen = [$chosen];
                $chosen = array_map('intval', $chosen);
                if (!in_array((int)$r['item_id'], $chosen, true)) {
                    continue; // gruppierte, nicht gewählte Option überspringen
                }
            }

            $planned = (float)$r['quantity'];
            $qty = isset($quantities[(string)$r['template_items_id']])
                ? $this->normalizeQty($quantities[(string)$r['template_items_id']])
                : $planned;

            $ins->execute([
                $userId, $packlistId, (int)$r['item_id'], $r['item_name'], $r['group_name'],
                $planned, $qty, (int)($r['order_index'] ?? $idx),
            ]);
        }
    }

    /**
     * Fügt der Packliste nachträglich eine Position hinzu. Optional wird die
     * Ergänzung als Standalone-Position in die Ursprungs-Vorlage übernommen.
     * Gibt die neue packlist_items_id zurück oder null (Artikel nicht gefunden).
     */
    protected function addPosition(int $packlistId, int $userId, int $itemId, float $quantity, bool $promoteToTemplate): ?int {
        $stmt = $this->pdo->prepare(
            "SELECT items_id, name FROM " . PREFIX . "_warehouse_items
             WHERE items_id = ? AND user_id = ? LIMIT 1"
        );
        $stmt->execute([$itemId, $userId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) return null;

        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(MAX(order_index) + 1, 0)
             FROM " . PREFIX . "_warehouse_packlist_items WHERE packlist_id = ?"
        );
        $stmt->execute([$packlistId]);
        $order = (int)$stmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            "INSERT INTO " . PREFIX . "_warehouse_packlist_items
               (user_id, packlist_id, item_id, item_name, quantity_planned, quantity, order_index)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$userId, $packlistId, $itemId, $item['name'], $quantity, $quantity, $order]);
        $posId = (int)$this->pdo->lastInsertId();

        if ($promoteToTemplate) {
            $stmt = $this->pdo->prepare(
                "SELECT template_id FROM " . PREFIX . "_warehouse_packlists
                 WHERE packlists_id = ? AND user_id = ? LIMIT 1"
            );
            $stmt->execute([$packlistId, $userId]);
            $templateId = $stmt->fetchColumn();
            if ($templateId) {
                $stmt = $this->pdo->prepare(
                    "SELECT COALESCE(MAX(order_index) + 1, 0)
                     FROM " . PREFIX . "_warehouse_packlist_template_items WHERE template_id = ?"
                );
                $stmt->execute([(int)$templateId]);
                $torder = (int)$stmt->fetchColumn();

                $stmt = $this->pdo->prepare(
                    "INSERT INTO " . PREFIX . "_warehouse_packlist_template_items
                       (user_id, template_id, group_id, item_id, quantity, order_index)
                     VALUES (?, ?, NULL, ?, ?, ?)"
                );
                $stmt->execute([$userId, (int)$templateId, $itemId, $quantity, $torder]);
            }
        }

        return $posId;
    }

    /**
     * Checkt eine Position aus (Phase A: reservieren). Prüft die Verfügbarkeit
     * (kein Über-Reservieren über den Bestand hinaus) und merkt den Lagerort als
     * Snapshot für die Rückgabe. Idempotent bei bereits ausgecheckten Positionen.
     *
     * @return array{ok:bool, code?:int, error?:string}
     */
    protected function checkOutPosition(int $positionId, int $packlistId, int $userId): array {
        $stmt = $this->pdo->prepare(
            "SELECT p.packlist_items_id, p.item_id, p.quantity, p.checked_out,
                    i.quantity AS stock, i.location_id
             FROM " . PREFIX . "_warehouse_packlist_items p
             LEFT JOIN " . PREFIX . "_warehouse_items i ON i.items_id = p.item_id
             WHERE p.packlist_items_id = ? AND p.packlist_id = ? AND p.user_id = ? LIMIT 1"
        );
        $stmt->execute([$positionId, $packlistId, $userId]);
        $pos = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pos) return ['ok' => false, 'code' => 404, 'error' => 'Position not found'];
        if ((int)$pos['checked_out'] === 1) return ['ok' => true]; // bereits gepackt
        if ($pos['item_id'] === null) return ['ok' => false, 'code' => 409, 'error' => 'Item no longer exists'];

        // Verfügbar = Bestand − andere aktive Reservierungen (diese Position zählt
        // noch nicht, da checked_out = 0).
        $available = (float)$pos['stock'] - $this->reservedFor((int)$pos['item_id']);
        if ((float)$pos['quantity'] > $available + 1e-9) {
            return ['ok' => false, 'code' => 409, 'error' => 'Insufficient available stock'];
        }

        $stmt = $this->pdo->prepare(
            "UPDATE " . PREFIX . "_warehouse_packlist_items
             SET checked_out = 1, checked_out_at = ?, source_location_id = ?,
                 returned = 0, returned_at = NULL
             WHERE packlist_items_id = ? AND user_id = ?"
        );
        $stmt->execute([date('Y-m-d H:i:s'), $pos['location_id'], $positionId, $userId]);
        return ['ok' => true];
    }

    /**
     * Führt eine Position zurück (Phase B). Die Reservierung endet, da die
     * Ledger-Aggregation returned = 0 verlangt → Bestand wird wieder verfügbar.
     *
     * @return array{ok:bool, code?:int, error?:string}
     */
    protected function returnPosition(int $positionId, int $packlistId, int $userId): array {
        $stmt = $this->pdo->prepare(
            "SELECT packlist_items_id FROM " . PREFIX . "_warehouse_packlist_items
             WHERE packlist_items_id = ? AND packlist_id = ? AND user_id = ? LIMIT 1"
        );
        $stmt->execute([$positionId, $packlistId, $userId]);
        if (!$stmt->fetchColumn()) {
            return ['ok' => false, 'code' => 404, 'error' => 'Position not found'];
        }

        $stmt = $this->pdo->prepare(
            "UPDATE " . PREFIX . "_warehouse_packlist_items
             SET returned = 1, returned_at = ?
             WHERE packlist_items_id = ? AND user_id = ?"
        );
        $stmt->execute([date('Y-m-d H:i:s'), $positionId, $userId]);
        return ['ok' => true];
    }

    /**
     * Macht das Auschecken rückgängig (Korrektur in Phase A): Position wird wieder
     * frei, Reservierung + Lagerort-Snapshot werden entfernt. Idempotent.
     *
     * @return array{ok:bool, code?:int, error?:string}
     */
    protected function uncheckOutPosition(int $positionId, int $packlistId, int $userId): array {
        $stmt = $this->pdo->prepare(
            "SELECT packlist_items_id FROM " . PREFIX . "_warehouse_packlist_items
             WHERE packlist_items_id = ? AND packlist_id = ? AND user_id = ? LIMIT 1"
        );
        $stmt->execute([$positionId, $packlistId, $userId]);
        if (!$stmt->fetchColumn()) {
            return ['ok' => false, 'code' => 404, 'error' => 'Position not found'];
        }

        $stmt = $this->pdo->prepare(
            "UPDATE " . PREFIX . "_warehouse_packlist_items
             SET checked_out = 0, checked_out_at = NULL, source_location_id = NULL,
                 returned = 0, returned_at = NULL
             WHERE packlist_items_id = ? AND user_id = ?"
        );
        $stmt->execute([$positionId, $userId]);
        return ['ok' => true];
    }

    /**
     * Macht das Zurückführen rückgängig (Korrektur in Phase B): Position gilt
     * wieder als ausgecheckt/reserviert. Idempotent.
     *
     * @return array{ok:bool, code?:int, error?:string}
     */
    protected function unreturnPosition(int $positionId, int $packlistId, int $userId): array {
        $stmt = $this->pdo->prepare(
            "SELECT packlist_items_id FROM " . PREFIX . "_warehouse_packlist_items
             WHERE packlist_items_id = ? AND packlist_id = ? AND user_id = ? LIMIT 1"
        );
        $stmt->execute([$positionId, $packlistId, $userId]);
        if (!$stmt->fetchColumn()) {
            return ['ok' => false, 'code' => 404, 'error' => 'Position not found'];
        }

        $stmt = $this->pdo->prepare(
            "UPDATE " . PREFIX . "_warehouse_packlist_items
             SET returned = 0, returned_at = NULL
             WHERE packlist_items_id = ? AND user_id = ?"
        );
        $stmt->execute([$positionId, $userId]);
        return ['ok' => true];
    }

    /**
     * Entfernt eine Position aus der Packliste (Korrektur). Eine etwaige aktive
     * Reservierung endet automatisch, da die Zeile — und damit ihr Ledger-Beitrag
     * — verschwindet. Idempotent (nicht vorhanden = ebenfalls ok).
     *
     * @return array{ok:bool}
     */
    protected function removePosition(int $positionId, int $packlistId, int $userId): array {
        $stmt = $this->pdo->prepare(
            "DELETE FROM " . PREFIX . "_warehouse_packlist_items
             WHERE packlist_items_id = ? AND packlist_id = ? AND user_id = ?"
        );
        $stmt->execute([$positionId, $packlistId, $userId]);
        return ['ok' => true];
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** Prüft, ob der Artikel dem User gehört. */
    protected function userOwnsItem(int $itemId, int $userId): bool {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM " . PREFIX . "_warehouse_items WHERE items_id = ? AND user_id = ? LIMIT 1"
        );
        $stmt->execute([$itemId, $userId]);
        return (bool)$stmt->fetchColumn();
    }

    /** Menge normalisieren (>= 0, 2 Nachkommastellen). */
    protected function normalizeQty(mixed $value): float {
        if (!is_numeric($value)) return 0.0;
        $v = round((float)$value, 2);
        return $v < 0 ? 0.0 : $v;
    }

    /** Packlisten-ID aus dem Request (Array oder Int). */
    protected function requestId(array $request): int {
        if (!isset($request['id'])) return 0;
        return is_array($request['id']) ? (int)$request['id'][0] : (int)$request['id'];
    }
}
