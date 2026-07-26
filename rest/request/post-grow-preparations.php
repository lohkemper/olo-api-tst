<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /grow/preparations — Legt ein Düngepräparat an.
 *
 * Body: { "name": "...", "type": "base|grow|bloom|additive|booster|flush",
 *         "default_unit": "ml/L", "warehouse_item_id": 12 (optional) }
 */
class requestPostGrowPreparations extends RequestBase {
    private array $data = [];

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');
            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $name = trim((string)($this->data['name'] ?? ''));
            if ($name === '') {
                http_response_code(400);
                echo json_encode(['error' => 'name is required']);
                return;
            }

            $allowedTypes = ['base', 'grow', 'bloom', 'additive', 'booster', 'flush'];
            $type = (string)($this->data['type'] ?? 'base');
            if (!in_array($type, $allowedTypes, true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid type']);
                return;
            }

            $warehouseItemId = $this->nullableUint($this->data['warehouse_item_id'] ?? null);
            if ($warehouseItemId !== null) {
                $stmt = $this->pdo->prepare('SELECT items_id FROM mbc_warehouse_items WHERE items_id = ? AND user_id = ?');
                $stmt->execute([$warehouseItemId, $userId]);
                if (!$stmt->fetch()) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Warehouse item not found or access denied']);
                    return;
                }
            }

            $status = ((string)($this->data['status'] ?? 'active')) === 'inactive' ? 'inactive' : 'active';
            $phase = $this->validPhase($this->data['phase'] ?? 'any');

            $stmt = $this->pdo->prepare(
                'INSERT INTO mbc_grow_preparations
                    (user_id, name, type, status, color, default_unit, default_dosage, phase,
                     ec_contribution, brand, notes, warehouse_item_id, meta)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                $name,
                $type,
                $status,
                $this->nullableStr($this->data['color'] ?? null),
                (string)($this->data['default_unit'] ?? 'ml/L'),
                (float)($this->data['default_dosage'] ?? 0),
                $phase,
                (float)($this->data['ec_contribution'] ?? 0),
                $this->nullableStr($this->data['brand'] ?? null),
                $this->nullableStr($this->data['notes'] ?? null),
                $warehouseItemId,
                isset($this->data['meta']) ? json_encode($this->data['meta']) : null,
            ]);

            $newId = (int)$this->pdo->lastInsertId();
            $stmt = $this->pdo->prepare(
                'SELECT grow_preparations_id, user_id, name, type, status, color, default_unit,
                        default_dosage, phase, ec_contribution, brand, notes, warehouse_item_id,
                        meta, created_at, updated_at
                 FROM mbc_grow_preparations WHERE grow_preparations_id = ?'
            );
            $stmt->execute([$newId]);

            http_response_code(201);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error creating grow preparation', $e);
        }
    }

    private function nullableUint(mixed $value): ?int {
        if ($value === null || $value === '' || $value === false) return null;
        if (!is_numeric($value)) return null;
        $i = (int)$value;
        return $i > 0 ? $i : null;
    }

    private function nullableStr(mixed $value): ?string {
        if ($value === null) return null;
        $s = trim((string)$value);
        return $s === '' ? null : $s;
    }

    /** Erlaubt eine PlantPhase oder 'any'; Fallback 'any'. */
    private function validPhase(mixed $value): string {
        $allowed = ['any', 'germination', 'seedling', 'vegetative', 'flowering', 'harvest', 'cure'];
        $v = (string)$value;
        return in_array($v, $allowed, true) ? $v : 'any';
    }
}
