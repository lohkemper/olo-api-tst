<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /gym/nutrition-entries
 *
 * Body:
 *  {
 *    "food_id": 12,
 *    "meal": "breakfast",
 *    "consumed_at": "2026-05-04 07:35:00",
 *    "amount_g": 80,
 *    "notes": "..."
 *  }
 */
class requestPostGymNutritionEntries extends RequestBase {
    private array $data = [];

    public function setData(array $data): void { $this->data = $data; }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $allowedMeals = ['breakfast','lunch','dinner','snack'];
            $foodId      = (int)($this->data['food_id'] ?? 0);
            $meal        = (string)($this->data['meal'] ?? '');
            $consumedAt  = (string)($this->data['consumed_at'] ?? '');
            $amount      = (float)($this->data['amount_g'] ?? 0);

            if ($foodId <= 0 || !in_array($meal, $allowedMeals, true) || $consumedAt === '' || $amount <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'food_id, meal, consumed_at and amount_g are required']);
                return;
            }

            // Food sichtbar (System-Template oder eigen)?
            $stmt = $this->pdo->prepare(
                'SELECT foods_id FROM mbc_gym_foods
                 WHERE foods_id = ? AND (user_id IS NULL OR user_id = ?)'
            );
            $stmt->execute([$foodId, $userId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Food not visible to this user']);
                return;
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO mbc_gym_nutrition_entries
                  (user_id, food_id, meal, consumed_at, amount_g, notes)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                $foodId,
                $meal,
                $consumedAt,
                $amount,
                $this->data['notes'] ?? null,
            ]);

            $newId = (int)$this->pdo->lastInsertId();

            // Same join shape wie GET damit Frontend-Mapping einheitlich bleibt
            $stmt = $this->pdo->prepare(
                'SELECT ne.nutrition_entries_id, ne.user_id, ne.food_id, ne.meal,
                        ne.consumed_at, ne.amount_g, ne.notes,
                        ne.created_at, ne.updated_at,
                        f.name AS food_name, f.brand AS food_brand,
                        f.kcal_per_100g, f.protein_per_100g, f.carbs_per_100g,
                        f.fat_per_100g, f.fiber_per_100g, f.sugar_per_100g
                 FROM mbc_gym_nutrition_entries ne
                 INNER JOIN mbc_gym_foods f ON f.foods_id = ne.food_id
                 WHERE ne.nutrition_entries_id = ?'
            );
            $stmt->execute([$newId]);
            http_response_code(201);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error creating gym nutrition entry', $e);
        }
    }
}
