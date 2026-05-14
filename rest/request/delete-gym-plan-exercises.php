<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * DELETE /gym/plan-exercises/{id} — Entfernt eine Übung aus einem Plan-Tag.
 */
class requestDeleteGymPlanExercises extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void { $this->request = $request; }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $peId = isset($this->request['id']) ? (int)$this->request['id'] : 0;
            if ($peId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Plan-exercise id required']);
                return;
            }

            $stmt = $this->pdo->prepare(
                'DELETE FROM mbc_gym_plan_exercises WHERE plan_exercises_id = ? AND user_id = ?'
            );
            $stmt->execute([$peId, $userId]);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Plan-exercise not found']);
                return;
            }

            http_response_code(204);
        } catch (\Throwable $e) {
            $this->handleError('Error deleting gym plan exercise', $e);
        }
    }
}
