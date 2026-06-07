<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /grow/feeding-schedule — Legt einen Dünge-Schedule-Eintrag für eine Pflanze an.
 *
 * Body: {
 *   "plant_id": 5, "preparation_id": 2,
 *   "interval_days": 7, "dosage": 2.5, "dosage_unit": "ml/L",
 *   "phase": "flowering" (optional),
 *   "start_offset_days": 0, "end_offset_days": 56 (optional),
 *   "notes": "..." (optional)
 * }
 */
class requestPostGrowFeedingSchedule extends RequestBase {
    private array $data = [];

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');
            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $plantId = (int)($this->data['plant_id'] ?? 0);
            $preparationId = (int)($this->data['preparation_id'] ?? 0);
            if ($plantId <= 0 || $preparationId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'plant_id and preparation_id are required']);
                return;
            }

            // Ownership: Pflanze + Präparat müssen dem User gehören
            $stmt = $this->pdo->prepare('SELECT grow_plants_id FROM mbc_grow_plants WHERE grow_plants_id = ? AND user_id = ?');
            $stmt->execute([$plantId, $userId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Plant not found']);
                return;
            }
            $stmt = $this->pdo->prepare('SELECT grow_preparations_id FROM mbc_grow_preparations WHERE grow_preparations_id = ? AND user_id = ?');
            $stmt->execute([$preparationId, $userId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Preparation not found']);
                return;
            }

            $dosage = (float)($this->data['dosage'] ?? 0);
            if ($dosage <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'dosage must be > 0']);
                return;
            }

            $allowedPhases = ['germination', 'seedling', 'vegetative', 'flowering', 'harvest', 'cure'];
            $phase = $this->data['phase'] ?? null;
            if ($phase !== null && !in_array((string)$phase, $allowedPhases, true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid phase']);
                return;
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO mbc_grow_feeding_schedule
                   (plant_id, preparation_id, phase, interval_days, dosage, dosage_unit,
                    start_offset_days, end_offset_days, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $plantId,
                $preparationId,
                $phase,
                max(1, (int)($this->data['interval_days'] ?? 7)),
                $dosage,
                (string)($this->data['dosage_unit'] ?? 'ml/L'),
                max(0, (int)($this->data['start_offset_days'] ?? 0)),
                isset($this->data['end_offset_days']) && $this->data['end_offset_days'] !== ''
                    ? (int)$this->data['end_offset_days'] : null,
                $this->data['notes'] ?? null,
            ]);

            $newId = (int)$this->pdo->lastInsertId();
            $stmt = $this->pdo->prepare(
                'SELECT s.grow_feeding_schedule_id, s.plant_id, s.preparation_id, s.phase,
                        s.interval_days, s.dosage, s.dosage_unit, s.start_offset_days,
                        s.end_offset_days, s.notes,
                        p.name AS preparation_name, p.type AS preparation_type
                 FROM mbc_grow_feeding_schedule s
                 INNER JOIN mbc_grow_preparations p ON p.grow_preparations_id = s.preparation_id
                 WHERE s.grow_feeding_schedule_id = ?'
            );
            $stmt->execute([$newId]);

            http_response_code(201);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error creating feeding schedule entry', $e);
        }
    }
}
