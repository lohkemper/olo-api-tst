<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * PUT /gym/exercises/{id} — User bearbeitet eine eigene Übung.
 *
 * System-Templates (user_id IS NULL) sind nicht editierbar — der WHERE-Filter
 * auf user_id = current verhindert das.
 *
 * Body (alles optional):
 *  {
 *    "name": "...",
 *    "slug": "...",
 *    "category_id": 12,
 *    "equipment_article_id": 47,    // FK auf mbc_warehouse_items
 *    "primary_muscle": "chest",
 *    "secondary_muscles": ["triceps","front_delts"],
 *    "exercise_type": "strength",
 *    "measurement_type": "weight_reps",
 *    "description": "...",
 *    "video_url": "...",
 *    "photo_url": "..."
 *  }
 */
class requestPutGymExercises extends RequestBase {
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
                echo json_encode(['error' => 'Exercise id required']);
                return;
            }

            $allowedTypes        = ['strength','cardio','mobility','bodyweight'];
            $allowedMeasurements = ['weight_reps','reps_only','time','distance','weight_time'];

            if (array_key_exists('exercise_type', $this->data) && !in_array((string)$this->data['exercise_type'], $allowedTypes, true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid exercise_type']);
                return;
            }
            if (array_key_exists('measurement_type', $this->data) && !in_array((string)$this->data['measurement_type'], $allowedMeasurements, true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid measurement_type']);
                return;
            }

            // Equipment muss dem User gehören (oder NULL)
            if (!empty($this->data['equipment_article_id'])) {
                $stmt = $this->pdo->prepare(
                    'SELECT items_id FROM mbc_warehouse_items WHERE items_id = ? AND user_id = ?'
                );
                $stmt->execute([(int)$this->data['equipment_article_id'], $userId]);
                if (!$stmt->fetch()) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Equipment item not found in your warehouse']);
                    return;
                }
            }

            $allowed = [
                'name','slug','category_id','equipment_article_id','primary_muscle',
                'secondary_muscles','exercise_type','measurement_type',
                'description','video_url','photo_url',
            ];
            $sets = [];
            $params = [];
            foreach ($allowed as $f) {
                if (!array_key_exists($f, $this->data)) continue;
                $value = $this->data[$f];
                if ($f === 'secondary_muscles' && $value !== null) {
                    $value = json_encode($value);
                }
                $sets[] = "$f = ?";
                $params[] = $value;
            }
            if (empty($sets)) {
                http_response_code(400);
                echo json_encode(['error' => 'No fields to update']);
                return;
            }

            $params[] = $id;
            $params[] = $userId;
            $sql = 'UPDATE mbc_gym_exercises SET ' . implode(', ', $sets)
                 . ' WHERE exercises_id = ? AND user_id = ?';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Exercise not found or not editable']);
                return;
            }

            $stmt = $this->pdo->prepare(
                'SELECT e.exercises_id, e.user_id, e.name, e.slug, e.category_id,
                        e.equipment_article_id, e.primary_muscle, e.secondary_muscles,
                        e.exercise_type, e.measurement_type, e.description,
                        e.video_url, e.photo_url, e.is_template, e.created_at, e.updated_at,
                        wi.name AS equipment_name
                 FROM mbc_gym_exercises e
                 LEFT JOIN mbc_warehouse_items wi ON wi.items_id = e.equipment_article_id
                 WHERE e.exercises_id = ?'
            );
            $stmt->execute([$id]);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                http_response_code(409);
                echo json_encode(['error' => 'Slug already exists for this user']);
                return;
            }
            $this->handleError('Error updating gym exercise', $e);
        } catch (\Throwable $e) {
            $this->handleError('Error updating gym exercise', $e);
        }
    }
}
