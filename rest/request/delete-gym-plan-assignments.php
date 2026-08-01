<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * DELETE /gym/plan-assignments/{id} — Coach zieht eine Zuweisung zurück.
 * Nur der Coach (assigned_by_user_id) darf löschen.
 */
class requestDeleteGymPlanAssignments extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void { $this->request = $request; }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $id = isset($this->request['id']) ? (int)$this->request['id'] : 0;
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Plan-assignment id required']);
                return;
            }

            $stmt = $this->pdo->prepare(
                'DELETE FROM mbc_gym_plan_assignments
                 WHERE plan_assignments_id = ? AND assigned_by_user_id = ?'
            );
            $stmt->execute([$id, $userId]);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Plan assignment not found or not yours']);
                return;
            }

            http_response_code(204);
        } catch (\Throwable $e) {
            $this->handleError('Error deleting gym plan assignment', $e);
        }
    }
}
