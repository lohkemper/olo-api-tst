<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /grow/plants — Legt eine Pflanze in einem Durchlauf an.
 *
 * Body:
 *  {
 *    "cycle_id": 12,                  // Pflicht — muss dem User gehören
 *    "label": "Pflanze 1",            // Pflicht
 *    "strain": "...",                 // optional
 *    "seed_item_id": 34,              // optional — Warenlager-Item des Users
 *    "planted_date": "2026-06-01",    // optional
 *    "current_phase": "germination",  // optional
 *    "expected_harvest_date": "...",  // optional
 *    "status": "active",              // optional
 *    "meta": { ... }                  // optional JSON
 *  }
 */
class requestPostGrowPlants extends RequestBase {
    private array $data = [];

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $cycleId = (int)($this->data['cycle_id'] ?? 0);
            if ($cycleId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'cycle_id is required']);
                return;
            }

            // Cycle-Ownership prüfen
            $stmt = $this->pdo->prepare('SELECT grow_cycles_id FROM mbc_grow_cycles WHERE grow_cycles_id = ? AND user_id = ?');
            $stmt->execute([$cycleId, $userId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Cycle not found']);
                return;
            }

            $label = trim((string)($this->data['label'] ?? ''));
            if ($label === '') {
                http_response_code(400);
                echo json_encode(['error' => 'label is required']);
                return;
            }

            $allowedPhases = ['germination', 'seedling', 'vegetative', 'flowering', 'harvest', 'cure'];
            $phase = (string)($this->data['current_phase'] ?? 'germination');
            if (!in_array($phase, $allowedPhases, true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid current_phase']);
                return;
            }

            $allowedStatus = ['active', 'harvested', 'dead', 'archived'];
            $status = (string)($this->data['status'] ?? 'active');
            if (!in_array($status, $allowedStatus, true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid status']);
                return;
            }

            // Seed-Item-Ownership prüfen (falls gesetzt)
            $seedItemId = $this->nullableUint($this->data['seed_item_id'] ?? null);
            if ($seedItemId !== null) {
                $stmt = $this->pdo->prepare('SELECT items_id FROM mbc_warehouse_items WHERE items_id = ? AND user_id = ?');
                $stmt->execute([$seedItemId, $userId]);
                if (!$stmt->fetch()) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Seed item not found or access denied']);
                    return;
                }
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO mbc_grow_plants
                   (cycle_id, user_id, label, strain, seed_item_id, planted_date,
                    current_phase, expected_harvest_date, status, meta)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $cycleId,
                $userId,
                $label,
                $this->data['strain'] ?? null,
                $seedItemId,
                $this->data['planted_date'] ?? null,
                $phase,
                $this->data['expected_harvest_date'] ?? null,
                $status,
                isset($this->data['meta']) ? json_encode($this->data['meta']) : null,
            ]);

            $newId = (int)$this->pdo->lastInsertId();

            $stmt = $this->pdo->prepare(
                'SELECT p.grow_plants_id, p.cycle_id, p.user_id, p.label, p.strain,
                        p.seed_item_id, p.planted_date, p.current_phase,
                        p.expected_harvest_date, p.actual_harvest_date, p.status,
                        p.meta, p.created_at, p.updated_at,
                        i.name AS seed_item_name
                 FROM mbc_grow_plants p
                 LEFT JOIN mbc_warehouse_items i ON i.items_id = p.seed_item_id
                 WHERE p.grow_plants_id = ?'
            );
            $stmt->execute([$newId]);

            http_response_code(201);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error creating grow plant', $e);
        }
    }

    /** Normalisiert zu positivem INT oder NULL. */
    private function nullableUint(mixed $value): ?int {
        if ($value === null || $value === '' || $value === false) return null;
        if (!is_numeric($value)) return null;
        $i = (int)$value;
        return $i > 0 ? $i : null;
    }
}
