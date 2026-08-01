<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * PUT /gym/nutrition-entries/{id}
 */
class requestPutGymNutritionEntries extends RequestBase {
    private array $request = [];
    private array $data = [];

    public function setRequest(array $request): void { $this->request = $request; }
    public function setData(array $data): void { $this->data = $data; }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $id = isset($this->request['id']) ? (int)$this->request['id'] : 0;
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Nutrition-entry id required']);
                return;
            }

            $allowedMeals = ['breakfast','lunch','dinner','snack'];
            if (array_key_exists('meal', $this->data) && !in_array((string)$this->data['meal'], $allowedMeals, true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid meal']);
                return;
            }

            $allowed = ['food_id','meal','consumed_at','amount_g','notes'];
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

            // Wenn food_id gewechselt: prüfen dass das neue Food sichtbar ist
            if (array_key_exists('food_id', $this->data)) {
                $stmt = $this->pdo->prepare(
                    'SELECT foods_id FROM mbc_gym_foods
                     WHERE foods_id = ? AND (user_id IS NULL OR user_id = ?)'
                );
                $stmt->execute([(int)$this->data['food_id'], $userId]);
                if (!$stmt->fetch()) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Food not visible to this user']);
                    return;
                }
            }

            $params[] = $id;
            $params[] = $userId;
            $sql = 'UPDATE mbc_gym_nutrition_entries SET ' . implode(', ', $sets)
                 . ' WHERE nutrition_entries_id = ? AND user_id = ?';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Nutrition-entry not found']);
                return;
            }

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
            $stmt->execute([$id]);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error updating gym nutrition entry', $e);
        }
    }
}
