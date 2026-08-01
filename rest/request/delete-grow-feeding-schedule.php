<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * DELETE /grow/feeding-schedule/{id} — Löscht einen Schedule-Eintrag.
 * Ownership über Join auf die Pflanze (plant.user_id).
 */
class requestDeleteGrowFeedingSchedule extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');
            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $id = isset($this->request['id']) ? (int)$this->request['id'] : 0;
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Schedule id required']);
                return;
            }

            $stmt = $this->pdo->prepare(
                'DELETE s FROM mbc_grow_feeding_schedule s
                 INNER JOIN mbc_grow_plants p ON p.grow_plants_id = s.plant_id
                 WHERE s.grow_feeding_schedule_id = ? AND p.user_id = ?'
            );
            $stmt->execute([$id, $userId]);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Schedule entry not found']);
                return;
            }

            http_response_code(204);
        } catch (\Throwable $e) {
            $this->handleError('Error deleting feeding schedule entry', $e);
        }
    }
}
