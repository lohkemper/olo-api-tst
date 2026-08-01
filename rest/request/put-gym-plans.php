<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * PUT /gym/plans/{id} — Aktualisiert Metadaten eines Plans.
 *
 * Body (alles optional): name, description, goal, weeks, is_active
 * Wenn is_active = true gesetzt wird, werden alle anderen Pläne des Users deaktiviert.
 */
class requestPutGymPlans extends RequestBase {
    private array $request = [];
    private array $data = [];

    public function setRequest(array $request): void { $this->request = $request; }
    public function setData(array $data): void { $this->data = $data; }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $planId = isset($this->request['id']) ? (int)$this->request['id'] : 0;
            if ($planId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Plan id required']);
                return;
            }

            // Ownership prüfen
            $stmt = $this->pdo->prepare('SELECT plans_id FROM mbc_gym_plans WHERE plans_id = ? AND user_id = ?');
            $stmt->execute([$planId, $userId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Plan not found']);
                return;
            }

            // is_active = true → alle anderen deaktivieren
            if (!empty($this->data['is_active'])) {
                $stmt = $this->pdo->prepare(
                    'UPDATE mbc_gym_plans SET is_active = 0 WHERE user_id = ? AND plans_id != ?'
                );
                $stmt->execute([$userId, $planId]);
            }

            $allowedGoals = ['strength','hypertrophy','endurance','general','cut','bulk'];
            $allowed = ['name','description','goal','weeks','is_active'];
            $sets = [];
            $params = [];
            foreach ($allowed as $f) {
                if (!array_key_exists($f, $this->data)) continue;
                $value = $this->data[$f];
                if ($f === 'goal' && !in_array((string)$value, $allowedGoals, true)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid goal']);
                    return;
                }
                if ($f === 'is_active') $value = $value ? 1 : 0;
                $sets[] = "$f = ?";
                $params[] = $value;
            }
            if (empty($sets)) {
                http_response_code(400);
                echo json_encode(['error' => 'No fields to update']);
                return;
            }

            $params[] = $planId;
            $params[] = $userId;
            $sql = 'UPDATE mbc_gym_plans SET ' . implode(', ', $sets)
                 . ' WHERE plans_id = ? AND user_id = ?';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            $stmt = $this->pdo->prepare(
                'SELECT plans_id, user_id, name, description, goal, weeks, is_active,
                        created_at, updated_at
                 FROM mbc_gym_plans WHERE plans_id = ?'
            );
            $stmt->execute([$planId]);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error updating gym plan', $e);
        }
    }
}
