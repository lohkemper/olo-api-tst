<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /gym/plans — Erstellt einen neuen Plan für den eingeloggten User.
 *
 * Body:
 *  {
 *    "name": "Push/Pull/Legs",
 *    "description": "...",       // optional
 *    "goal": "hypertrophy",      // optional (default: general)
 *    "weeks": 12,                // optional (default: NULL)
 *    "is_active": false          // optional (default: false)
 *  }
 */
class requestPostGymPlans extends RequestBase {
    private array $data = [];

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $name = trim((string)($this->data['name'] ?? ''));
            if ($name === '') {
                http_response_code(400);
                echo json_encode(['error' => 'name is required']);
                return;
            }

            $allowedGoals = ['strength','hypertrophy','endurance','general','cut','bulk'];
            $goal = (string)($this->data['goal'] ?? 'general');
            if (!in_array($goal, $allowedGoals, true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid goal']);
                return;
            }

            $isActive = !empty($this->data['is_active']) ? 1 : 0;

            // Wenn neuer Plan aktiv ist, alle anderen Pläne des Users deaktivieren
            if ($isActive) {
                $stmt = $this->pdo->prepare(
                    'UPDATE mbc_gym_plans SET is_active = 0 WHERE user_id = ?'
                );
                $stmt->execute([$userId]);
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO mbc_gym_plans
                   (user_id, name, description, goal, weeks, is_active)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                $name,
                $this->data['description'] ?? null,
                $goal,
                $this->data['weeks'] ?? null,
                $isActive,
            ]);

            $newId = (int)$this->pdo->lastInsertId();

            $stmt = $this->pdo->prepare(
                'SELECT plans_id, user_id, name, description, goal, weeks, is_active,
                        created_at, updated_at
                 FROM mbc_gym_plans WHERE plans_id = ?'
            );
            $stmt->execute([$newId]);
            $plan = $stmt->fetch(PDO::FETCH_ASSOC);
            $plan['day_count'] = 0;
            $plan['days'] = [];

            http_response_code(201);
            echo json_encode($plan);
        } catch (\Throwable $e) {
            $this->handleError('Error creating gym plan', $e);
        }
    }
}
