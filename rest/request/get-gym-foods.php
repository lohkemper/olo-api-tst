<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /gym/foods               — System + eigene
 * GET /gym/foods/{id}          — Detail (sichtbar wenn Template oder eigen)
 * GET /gym/foods?q=hahn        — Volltext-Suche im Namen
 */
class requestGetGymFoods extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void { $this->request = $request; }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $id = isset($this->request['id']) ? (int)$this->request['id'] : null;
            if ($id !== null) {
                $this->getById($userId, $id);
            } else {
                $this->getList($userId);
            }
        } catch (\Throwable $e) {
            $this->handleError('Error fetching gym foods', $e);
        }
    }

    private function getList(int $userId): void {
        $sql = 'SELECT foods_id, user_id, name, brand, serving_size_g,
                       kcal_per_100g, protein_per_100g, carbs_per_100g,
                       fat_per_100g, fiber_per_100g, sugar_per_100g,
                       barcode, is_template, created_at, updated_at
                FROM mbc_gym_foods
                WHERE (user_id IS NULL OR user_id = ?)';
        $params = [$userId];

        if (!empty($this->request['q'])) {
            $sql .= ' AND (name LIKE ? OR brand LIKE ?)';
            $like = '%' . $this->request['q'] . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql .= ' ORDER BY name ASC LIMIT 200';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function getById(int $userId, int $id): void {
        $stmt = $this->pdo->prepare(
            'SELECT foods_id, user_id, name, brand, serving_size_g,
                    kcal_per_100g, protein_per_100g, carbs_per_100g,
                    fat_per_100g, fiber_per_100g, sugar_per_100g,
                    barcode, is_template, created_at, updated_at
             FROM mbc_gym_foods
             WHERE foods_id = ? AND (user_id IS NULL OR user_id = ?)
             LIMIT 1'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Food not found']);
            return;
        }

        echo json_encode($row);
    }
}
