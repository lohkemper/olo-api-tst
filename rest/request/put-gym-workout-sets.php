<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * PUT /gym/workout-sets/{id} — Aktualisiert ein Set (Reps/Gewicht/Notes/etc.).
 *
 * Body (alle Felder optional):
 *  {
 *    "reps": 9,
 *    "weight_kg": 82.5,
 *    "rpe": 9.0,
 *    "is_warmup": false,
 *    "is_failure": true,
 *    "notes": "Top-Form"
 *  }
 */
class requestPutGymWorkoutSets extends RequestBase {
    private array $request = [];
    private array $data = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $setId = isset($this->request['id']) ? (int)$this->request['id'] : 0;
            if ($setId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Set id required']);
                return;
            }

            $allowed = [
                'reps', 'weight_kg', 'time_seconds', 'distance_m', 'rpe',
                'is_warmup', 'is_failure', 'notes', 'set_index', 'performed_at',
            ];
            $sets = [];
            $params = [];
            foreach ($allowed as $f) {
                if (array_key_exists($f, $this->data)) {
                    $value = $this->data[$f];
                    if ($f === 'is_warmup' || $f === 'is_failure') {
                        $value = !empty($value) ? 1 : 0;
                    }
                    $sets[] = "$f = ?";
                    $params[] = $value;
                }
            }
            if (empty($sets)) {
                http_response_code(400);
                echo json_encode(['error' => 'No fields to update']);
                return;
            }

            $params[] = $setId;
            $params[] = $userId;
            $sql = 'UPDATE mbc_gym_workout_sets SET ' . implode(', ', $sets)
                 . ' WHERE workout_sets_id = ? AND user_id = ?';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Set not found']);
                return;
            }

            $stmt = $this->pdo->prepare(
                'SELECT workout_sets_id, user_id, workout_id, exercise_id, set_index,
                        reps, weight_kg, time_seconds, distance_m, rpe,
                        is_warmup, is_failure, notes, performed_at, created_at, updated_at
                 FROM mbc_gym_workout_sets WHERE workout_sets_id = ?'
            );
            $stmt->execute([$setId]);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error updating gym workout set', $e);
        }
    }
}
