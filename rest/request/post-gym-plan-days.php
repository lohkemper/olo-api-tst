<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /gym/plan-days — Fügt einen Tag zu einem Plan hinzu.
 *
 * Body:
 *  { "plan_id": 5, "name": "Push Day A", "day_index": 1, "notes": "..." }
 */
class requestPostGymPlanDays extends RequestBase {
    private array $data = [];

    public function setData(array $data): void { $this->data = $data; }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $planId = (int)($this->data['plan_id'] ?? 0);
            $name = trim((string)($this->data['name'] ?? ''));
            if ($planId <= 0 || $name === '') {
                http_response_code(400);
                echo json_encode(['error' => 'plan_id and name are required']);
                return;
            }

            $stmt = $this->pdo->prepare('SELECT plans_id FROM mbc_gym_plans WHERE plans_id = ? AND user_id = ?');
            $stmt->execute([$planId, $userId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Plan not found']);
                return;
            }

            // day_index automatisch ermitteln, falls nicht übergeben
            $dayIndex = $this->data['day_index'] ?? null;
            if ($dayIndex === null) {
                $stmt = $this->pdo->prepare(
                    'SELECT COALESCE(MAX(day_index), 0) + 1 AS next_idx
                     FROM mbc_gym_plan_days WHERE plan_id = ?'
                );
                $stmt->execute([$planId]);
                $dayIndex = (int)$stmt->fetch(PDO::FETCH_ASSOC)['next_idx'];
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO mbc_gym_plan_days (user_id, plan_id, day_index, name, notes)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                $planId,
                (int)$dayIndex,
                $name,
                $this->data['notes'] ?? null,
            ]);

            $newId = (int)$this->pdo->lastInsertId();
            $stmt = $this->pdo->prepare(
                'SELECT plan_days_id, user_id, plan_id, day_index, name, notes,
                        created_at, updated_at
                 FROM mbc_gym_plan_days WHERE plan_days_id = ?'
            );
            $stmt->execute([$newId]);
            $day = $stmt->fetch(PDO::FETCH_ASSOC);
            $day['exercises'] = [];

            http_response_code(201);
            echo json_encode($day);
        } catch (\Throwable $e) {
            $this->handleError('Error creating gym plan day', $e);
        }
    }
}
