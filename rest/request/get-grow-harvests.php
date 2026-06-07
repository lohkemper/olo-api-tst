<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /grow/harvests?plant_id={id} — Ernte-Einträge einer Pflanze.
 * Ownership: Pflanze muss dem User gehören.
 */
class requestGetGrowHarvests extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');
            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $plantId = isset($this->request['plant_id']) ? (int)$this->request['plant_id'] : 0;
            if ($plantId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'plant_id is required']);
                return;
            }

            $stmt = $this->pdo->prepare('SELECT grow_plants_id FROM mbc_grow_plants WHERE grow_plants_id = ? AND user_id = ?');
            $stmt->execute([$plantId, $userId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Plant not found']);
                return;
            }

            $stmt = $this->pdo->prepare(
                'SELECT grow_harvests_id, plant_id, harvest_date, wet_weight_g, dry_weight_g,
                        quality_rating, notes, created_at
                 FROM mbc_grow_harvests
                 WHERE plant_id = ?
                 ORDER BY harvest_date DESC, grow_harvests_id DESC'
            );
            $stmt->execute([$plantId]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error fetching harvests', $e);
        }
    }
}
