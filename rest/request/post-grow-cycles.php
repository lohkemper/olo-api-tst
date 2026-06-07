<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /grow/cycles — Erstellt einen neuen Aufzucht-Durchlauf.
 *
 * Body:
 *  {
 *    "name": "Sommer 2026",
 *    "start_date": "2026-06-01",     // Pflicht (YYYY-MM-DD)
 *    "description": "...",            // optional
 *    "flowering_weeks": 10,           // optional (8-12 typisch, default 10)
 *    "status": "planned",             // optional (planned|active|harvested|archived)
 *    "expected_harvest_date": "...",  // optional
 *    "phase_config": { ... },         // optional JSON
 *    "meta": { ... }                  // optional JSON
 *  }
 */
class requestPostGrowCycles extends RequestBase {
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

            $startDate = trim((string)($this->data['start_date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
                http_response_code(400);
                echo json_encode(['error' => 'start_date (YYYY-MM-DD) is required']);
                return;
            }

            $allowedStatus = ['planned', 'active', 'harvested', 'archived'];
            $status = (string)($this->data['status'] ?? 'planned');
            if (!in_array($status, $allowedStatus, true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid status']);
                return;
            }

            $floweringWeeks = (int)($this->data['flowering_weeks'] ?? 10);
            if ($floweringWeeks < 1 || $floweringWeeks > 52) {
                $floweringWeeks = 10;
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO mbc_grow_cycles
                   (user_id, name, description, start_date, flowering_weeks,
                    expected_harvest_date, status, phase_config, meta)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                $name,
                $this->data['description'] ?? null,
                $startDate,
                $floweringWeeks,
                $this->data['expected_harvest_date'] ?? null,
                $status,
                isset($this->data['phase_config']) ? json_encode($this->data['phase_config']) : null,
                isset($this->data['meta']) ? json_encode($this->data['meta']) : null,
            ]);

            $newId = (int)$this->pdo->lastInsertId();

            $stmt = $this->pdo->prepare(
                'SELECT grow_cycles_id, user_id, name, description, start_date,
                        flowering_weeks, expected_harvest_date, status,
                        phase_config, meta, created_at, updated_at
                 FROM mbc_grow_cycles WHERE grow_cycles_id = ?'
            );
            $stmt->execute([$newId]);
            $cycle = $stmt->fetch(PDO::FETCH_ASSOC);
            $cycle['plant_count'] = 0;
            $cycle['plants'] = [];

            http_response_code(201);
            echo json_encode($cycle);
        } catch (\Throwable $e) {
            $this->handleError('Error creating grow cycle', $e);
        }
    }
}
