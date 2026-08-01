<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * PUT handler for warehouse-packlists endpoint
 * Handles:
 * - PUT /warehouse-packlists/{id}              — Header + Mengen der Positionen
 * - PUT /warehouse-packlists/{id}/check-out    — Position reservieren (Phase A)
 * - PUT /warehouse-packlists/{id}/return       — Position zurückführen (Phase B)
 * - PUT /warehouse-packlists/{id}/status       — Status setzen
 *
 * Sub-Actions erhalten die Positions-ID im Body: { position_id }.
 *
 * @version 1.0.0
 */
class requestPutWarehousePacklists extends WarehousePacklistBase {
    private const STATUSES = ['draft', 'packing', 'packed', 'returning', 'closed', 'cancelled'];

    private array $request = [];
    private array $data = [];

    public function setRequest(array $request): void { $this->request = $request; }
    public function setData(array $data): void { $this->data = $data; }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->getCurrentUser();
            if (!$user) {
                http_response_code(401);
                echo json_encode(['error' => 'Authentication required']);
                return;
            }
            $userId = (int)$user['users_id'];

            $packlistId = $this->requestId($this->request);
            if ($packlistId <= 0 || !$this->findPacklist($packlistId, $userId)) {
                http_response_code(404);
                echo json_encode(['error' => 'Packlist not found']);
                return;
            }

            $subroute = $this->request['subroute'] ?? '';
            switch ($subroute) {
                case 'check-out':
                    $this->handleCheckOut($userId, $packlistId);
                    break;
                case 'return':
                    $this->handleReturn($userId, $packlistId);
                    break;
                case 'status':
                    $this->handleStatus($userId, $packlistId);
                    break;
                case 'remove-item':
                    $this->handleRemoveItem($userId, $packlistId);
                    break;
                case '':
                    $this->handleUpdate($userId, $packlistId);
                    break;
                default:
                    http_response_code(404);
                    echo json_encode(['error' => 'Unknown subroute']);
            }
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->handleError('Error updating packlist', $e);
        }
    }

    /** PUT /warehouse-packlists/{id} — Header-Diff + optionale Positions-Mengen. */
    private function handleUpdate(int $userId, int $packlistId): void {
        $this->pdo->beginTransaction();

        $updates = [];
        $params = [];
        foreach (['name', 'borrower_name', 'borrower_contact', 'notes'] as $col) {
            if (array_key_exists($col, $this->data)) {
                $updates[] = "$col = ?";
                $params[] = $this->data[$col];
            }
        }
        foreach (['borrowed_at', 'due_at', 'returned_at'] as $col) {
            if (array_key_exists($col, $this->data)) {
                $updates[] = "$col = ?";
                $params[] = $this->nullableDate($this->data[$col]);
            }
        }
        if (array_key_exists('meta', $this->data)) {
            $updates[] = 'meta = ?';
            $params[] = $this->data['meta'] !== null ? json_encode($this->data['meta']) : null;
        }
        if (array_key_exists('status', $this->data) && in_array($this->data['status'], self::STATUSES, true)) {
            $updates[] = 'status = ?';
            $params[] = $this->data['status'];
        }

        if (!empty($updates)) {
            $params[] = $packlistId;
            $params[] = $userId;
            $sql = "UPDATE " . PREFIX . "_warehouse_packlists
                    SET " . implode(', ', $updates) . "
                    WHERE packlists_id = ? AND user_id = ?";
            $this->pdo->prepare($sql)->execute($params);
        }

        // Positions-Mengen anpassen: [{ packlist_items_id, quantity }]
        if (isset($this->data['positions']) && is_array($this->data['positions'])) {
            $upd = $this->pdo->prepare(
                "UPDATE " . PREFIX . "_warehouse_packlist_items
                 SET quantity = ?
                 WHERE packlist_items_id = ? AND packlist_id = ? AND user_id = ?"
            );
            foreach ($this->data['positions'] as $p) {
                if (!isset($p['packlist_items_id'])) continue;
                $upd->execute([
                    $this->normalizeQty($p['quantity'] ?? 0),
                    (int)$p['packlist_items_id'], $packlistId, $userId,
                ]);
            }
        }

        $this->pdo->commit();

        http_response_code(200);
        echo json_encode($this->buildPacklistDetail($packlistId, $userId));
    }

    /**
     * PUT /warehouse-packlists/{id}/check-out — Body: { position_id, checked? }.
     * checked=false macht das Auschecken rückgängig (Korrektur).
     */
    private function handleCheckOut(int $userId, int $packlistId): void {
        $positionId = (int)($this->data['position_id'] ?? 0);
        if ($positionId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'position_id is required']);
            return;
        }

        // Default true (abhaken); explizit false = rückgängig
        $checked = array_key_exists('checked', $this->data) ? (bool)$this->data['checked'] : true;

        if ($checked) {
            $result = $this->checkOutPosition($positionId, $packlistId, $userId);
        } else {
            $result = $this->uncheckOutPosition($positionId, $packlistId, $userId);
        }

        if (!$result['ok']) {
            http_response_code($result['code'] ?? 409);
            echo json_encode(['error' => $result['error'] ?? 'Check-out failed']);
            return;
        }

        // Beim ersten Packen draft → packing (nur vorwärts)
        if ($checked) {
            $this->pdo->prepare(
                "UPDATE " . PREFIX . "_warehouse_packlists
                 SET status = 'packing'
                 WHERE packlists_id = ? AND user_id = ? AND status = 'draft'"
            )->execute([$packlistId, $userId]);
        }

        http_response_code(200);
        echo json_encode($this->buildPacklistDetail($packlistId, $userId));
    }

    /**
     * PUT /warehouse-packlists/{id}/return — Body: { position_id, returned? }.
     * returned=false macht das Zurückführen rückgängig (Korrektur).
     */
    private function handleReturn(int $userId, int $packlistId): void {
        $positionId = (int)($this->data['position_id'] ?? 0);
        if ($positionId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'position_id is required']);
            return;
        }

        $returned = array_key_exists('returned', $this->data) ? (bool)$this->data['returned'] : true;

        if ($returned) {
            $result = $this->returnPosition($positionId, $packlistId, $userId);
        } else {
            $result = $this->unreturnPosition($positionId, $packlistId, $userId);
        }

        if (!$result['ok']) {
            http_response_code($result['code'] ?? 409);
            echo json_encode(['error' => $result['error'] ?? 'Return failed']);
            return;
        }

        http_response_code(200);
        echo json_encode($this->buildPacklistDetail($packlistId, $userId));
    }

    /** PUT /warehouse-packlists/{id}/remove-item — Body: { position_id }. */
    private function handleRemoveItem(int $userId, int $packlistId): void {
        $positionId = (int)($this->data['position_id'] ?? 0);
        if ($positionId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'position_id is required']);
            return;
        }

        $this->removePosition($positionId, $packlistId, $userId);

        http_response_code(200);
        echo json_encode($this->buildPacklistDetail($packlistId, $userId));
    }

    /** PUT /warehouse-packlists/{id}/status — Body: { status }. */
    private function handleStatus(int $userId, int $packlistId): void {
        $status = $this->data['status'] ?? '';
        if (!in_array($status, self::STATUSES, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid status']);
            return;
        }

        // Beim Abschluss (closed) das Rückgabedatum setzen, falls noch leer
        $setReturned = ($status === 'closed') ? ", returned_at = COALESCE(returned_at, CURDATE())" : "";

        $stmt = $this->pdo->prepare(
            "UPDATE " . PREFIX . "_warehouse_packlists
             SET status = ?$setReturned
             WHERE packlists_id = ? AND user_id = ?"
        );
        $stmt->execute([$status, $packlistId, $userId]);

        http_response_code(200);
        echo json_encode($this->buildPacklistDetail($packlistId, $userId));
    }

    /** Leeres/ungültiges Datum → NULL, sonst YYYY-MM-DD. */
    private function nullableDate(mixed $value): ?string {
        if (!is_string($value) || $value === '') return null;
        return substr($value, 0, 10);
    }
}
