<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /gym/foods — User legt eigenes Lebensmittel an (is_template = 0).
 *
 * Body:
 *  {
 *    "name": "Hähnchenbrust gewürzt", "brand": "Edeka",
 *    "serving_size_g": 150,
 *    "kcal_per_100g": 165, "protein_per_100g": 31,
 *    "carbs_per_100g": 0,  "fat_per_100g": 3.6,
 *    "fiber_per_100g": null, "sugar_per_100g": null,
 *    "barcode": "4029764001807"
 *  }
 */
class requestPostGymFoods extends RequestBase {
    private array $data = [];

    public function setData(array $data): void { $this->data = $data; }

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

            // Numerische Pflichtfelder
            foreach (['kcal_per_100g','protein_per_100g','carbs_per_100g','fat_per_100g'] as $f) {
                if (!isset($this->data[$f]) || !is_numeric($this->data[$f])) {
                    http_response_code(400);
                    echo json_encode(['error' => "$f is required and must be numeric"]);
                    return;
                }
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO mbc_gym_foods
                  (user_id, name, brand, serving_size_g,
                   kcal_per_100g, protein_per_100g, carbs_per_100g, fat_per_100g,
                   fiber_per_100g, sugar_per_100g, barcode, is_template)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)'
            );
            $stmt->execute([
                $userId,
                $name,
                $this->data['brand']           ?? null,
                $this->data['serving_size_g']  ?? 100,
                $this->data['kcal_per_100g'],
                $this->data['protein_per_100g'],
                $this->data['carbs_per_100g'],
                $this->data['fat_per_100g'],
                $this->data['fiber_per_100g']  ?? null,
                $this->data['sugar_per_100g']  ?? null,
                $this->data['barcode']         ?? null,
            ]);

            $newId = (int)$this->pdo->lastInsertId();
            $stmt = $this->pdo->prepare(
                'SELECT foods_id, user_id, name, brand, serving_size_g,
                        kcal_per_100g, protein_per_100g, carbs_per_100g,
                        fat_per_100g, fiber_per_100g, sugar_per_100g,
                        barcode, is_template, created_at, updated_at
                 FROM mbc_gym_foods WHERE foods_id = ?'
            );
            $stmt->execute([$newId]);
            http_response_code(201);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error creating gym food', $e);
        }
    }
}
