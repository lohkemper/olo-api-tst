<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /gym/nutrition-entries?date=YYYY-MM-DD
 *   → Alle Mahlzeiten eines Tages mit Lebensmittel-Details + berechneten Makros pro Eintrag.
 *
 * GET /gym/nutrition-entries
 *   → Alle Einträge (paginiert, default 200).
 */
class requestGetGymNutritionEntries extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void { $this->request = $request; }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $sql = 'SELECT ne.nutrition_entries_id, ne.user_id, ne.food_id, ne.meal,
                           ne.consumed_at, ne.amount_g, ne.notes,
                           ne.created_at, ne.updated_at,
                           f.name AS food_name, f.brand AS food_brand,
                           f.kcal_per_100g, f.protein_per_100g, f.carbs_per_100g,
                           f.fat_per_100g, f.fiber_per_100g, f.sugar_per_100g
                    FROM mbc_gym_nutrition_entries ne
                    INNER JOIN mbc_gym_foods f ON f.foods_id = ne.food_id
                    WHERE ne.user_id = ?';
            $params = [$userId];

            if (!empty($this->request['date'])) {
                $sql .= ' AND DATE(ne.consumed_at) = ?';
                $params[] = $this->request['date'];
            } elseif (!empty($this->request['from']) && !empty($this->request['to'])) {
                $sql .= ' AND ne.consumed_at >= ? AND ne.consumed_at <= ?';
                $params[] = $this->request['from'];
                $params[] = $this->request['to'];
            }

            $sql .= ' ORDER BY ne.consumed_at ASC LIMIT 200';

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error fetching gym nutrition entries', $e);
        }
    }
}
