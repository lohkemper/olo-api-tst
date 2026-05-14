<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * DELETE /gym/plan-days/{id} — Löscht einen Plan-Tag (Cascade entfernt Plan-Exercises).
 */
class requestDeleteGymPlanDays extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void { $this->request = $request; }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $dayId = isset($this->request['id']) ? (int)$this->request['id'] : 0;
            if ($dayId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Plan-day id required']);
                return;
            }

            $stmt = $this->pdo->prepare(
                'DELETE FROM mbc_gym_plan_days WHERE plan_days_id = ? AND user_id = ?'
            );
            $stmt->execute([$dayId, $userId]);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Plan-day not found']);
                return;
            }

            http_response_code(204);
        } catch (\Throwable $e) {
            $this->handleError('Error deleting gym plan day', $e);
        }
    }
}
