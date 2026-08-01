<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * PUT /gym/foods/{id} — Eigene Lebensmittel bearbeiten (System nicht).
 */
class requestPutGymFoods extends RequestBase {
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
                echo json_encode(['error' => 'Food id required']);
                return;
            }

            $allowed = [
                'name','brand','serving_size_g',
                'kcal_per_100g','protein_per_100g','carbs_per_100g','fat_per_100g',
                'fiber_per_100g','sugar_per_100g','barcode',
            ];
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

            $params[] = $id;
            $params[] = $userId;
            // System-Templates (user_id IS NULL) sind nicht änderbar — Filter erzwingt das
            $sql = 'UPDATE mbc_gym_foods SET ' . implode(', ', $sets)
                 . ' WHERE foods_id = ? AND user_id = ?';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Food not found or not editable']);
                return;
            }

            $stmt = $this->pdo->prepare(
                'SELECT foods_id, user_id, name, brand, serving_size_g,
                        kcal_per_100g, protein_per_100g, carbs_per_100g,
                        fat_per_100g, fiber_per_100g, sugar_per_100g,
                        barcode, is_template, created_at, updated_at
                 FROM mbc_gym_foods WHERE foods_id = ?'
            );
            $stmt->execute([$id]);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error updating gym food', $e);
        }
    }
}
