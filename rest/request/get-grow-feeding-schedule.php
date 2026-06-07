<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /grow/feeding-schedule?plant_id={id} — Dünge-Schedule-Einträge einer Pflanze.
 *
 * Ownership: Pflanze muss dem User gehören. Präparat-Name wird gejoint.
 */
class requestGetGrowFeedingSchedule extends RequestBase {
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

            // Pflanzen-Ownership prüfen
            $stmt = $this->pdo->prepare('SELECT grow_plants_id FROM mbc_grow_plants WHERE grow_plants_id = ? AND user_id = ?');
            $stmt->execute([$plantId, $userId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Plant not found']);
                return;
            }

            $stmt = $this->pdo->prepare(
                'SELECT s.grow_feeding_schedule_id, s.plant_id, s.preparation_id, s.phase,
                        s.interval_days, s.dosage, s.dosage_unit, s.start_offset_days,
                        s.end_offset_days, s.notes,
                        p.name AS preparation_name, p.type AS preparation_type
                 FROM mbc_grow_feeding_schedule s
                 INNER JOIN mbc_grow_preparations p ON p.grow_preparations_id = s.preparation_id
                 WHERE s.plant_id = ?
                 ORDER BY s.start_offset_days ASC, s.grow_feeding_schedule_id ASC'
            );
            $stmt->execute([$plantId]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error fetching feeding schedule', $e);
        }
    }
}
