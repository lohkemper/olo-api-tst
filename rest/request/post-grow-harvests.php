<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /grow/harvests — Legt einen Ernte-Eintrag für eine Pflanze an.
 *
 * Body: {
 *   "plant_id": 5, "harvest_date": "2026-06-07",   // Pflicht
 *   "wet_weight_g": 120.5, "dry_weight_g": 28.0,    // optional
 *   "quality_rating": 4,                            // optional 1-5
 *   "notes": "..."                                  // optional
 * }
 */
class requestPostGrowHarvests extends RequestBase {
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
            $harvestDate = trim((string)($this->data['harvest_date'] ?? ''));
            if ($plantId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'plant_id is required']);
                return;
            }
            if ($harvestDate === '') {
                http_response_code(400);
                echo json_encode(['error' => 'harvest_date (YYYY-MM-DD) is required']);
                return;
            }

            // Ownership: Pflanze muss dem User gehören
            $stmt = $this->pdo->prepare('SELECT grow_plants_id FROM mbc_grow_plants WHERE grow_plants_id = ? AND user_id = ?');
            $stmt->execute([$plantId, $userId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Plant not found']);
                return;
            }

            $quality = isset($this->data['quality_rating']) && $this->data['quality_rating'] !== ''
                ? (int)$this->data['quality_rating'] : null;
            if ($quality !== null && ($quality < 1 || $quality > 5)) {
                http_response_code(400);
                echo json_encode(['error' => 'quality_rating must be between 1 and 5']);
                return;
            }

            $wet = isset($this->data['wet_weight_g']) && $this->data['wet_weight_g'] !== ''
                ? (float)$this->data['wet_weight_g'] : null;
            $dry = isset($this->data['dry_weight_g']) && $this->data['dry_weight_g'] !== ''
                ? (float)$this->data['dry_weight_g'] : null;

            $stmt = $this->pdo->prepare(
                'INSERT INTO mbc_grow_harvests
                   (plant_id, harvest_date, wet_weight_g, dry_weight_g, quality_rating, notes)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $plantId,
                $harvestDate,
                $wet,
                $dry,
                $quality,
                $this->data['notes'] ?? null,
            ]);

            $newId = (int)$this->pdo->lastInsertId();
            $stmt = $this->pdo->prepare(
                'SELECT grow_harvests_id, plant_id, harvest_date, wet_weight_g, dry_weight_g,
                        quality_rating, notes, created_at
                 FROM mbc_grow_harvests WHERE grow_harvests_id = ?'
            );
            $stmt->execute([$newId]);

            http_response_code(201);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error creating harvest', $e);
        }
    }
}
