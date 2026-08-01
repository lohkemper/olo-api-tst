<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * PUT /gym/plan-days/{id} — Aktualisiert Name/Index/Notes eines Plan-Tages.
 */
class requestPutGymPlanDays extends RequestBase {
    private array $request = [];
    private array $data = [];

    public function setRequest(array $request): void { $this->request = $request; }
    public function setData(array $data): void { $this->data = $data; }

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

            $allowed = ['name','day_index','notes'];
            $sets = [];
            $params = [];
            foreach ($allowed as $f) {
                if (array_key_exists($f, $this->data)) {
                    $sets[] = "$f = ?";
                    $params[] = $this->data[$f];
                }
            }
            if (empty($sets)) {
                http_response_code(400);
                echo json_encode(['error' => 'No fields to update']);
                return;
            }

            $params[] = $dayId;
            $params[] = $userId;
            $sql = 'UPDATE mbc_gym_plan_days SET ' . implode(', ', $sets)
                 . ' WHERE plan_days_id = ? AND user_id = ?';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Plan-day not found']);
                return;
            }

            $stmt = $this->pdo->prepare(
                'SELECT plan_days_id, user_id, plan_id, day_index, name, notes,
                        created_at, updated_at
                 FROM mbc_gym_plan_days WHERE plan_days_id = ?'
            );
            $stmt->execute([$dayId]);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error updating gym plan day', $e);
        }
    }
}
