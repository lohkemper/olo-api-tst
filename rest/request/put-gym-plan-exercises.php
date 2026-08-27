<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * PUT /gym/plan-exercises/{id} — Aktualisiert eine Plan-Übung (Targets/Notes).
 */
class requestPutGymPlanExercises extends RequestBase {
    private array $request = [];
    private array $data = [];

    public function setRequest(array $request): void { $this->request = $request; }
    public function setData(array $data): void { $this->data = $data; }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $peId = isset($this->request['id']) ? (int)$this->request['id'] : 0;
            if ($peId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Plan-exercise id required']);
                return;
            }

            $allowed = [
                'order_index', 'target_sets', 'target_reps_min', 'target_reps_max',
                'target_weight_kg', 'target_rpe', 'rest_seconds', 'notes',
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

            $params[] = $peId;
            $params[] = $userId;
            $sql = 'UPDATE mbc_gym_plan_exercises SET ' . implode(', ', $sets)
                 . ' WHERE plan_exercises_id = ? AND user_id = ?';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Plan-exercise not found']);
                return;
            }

            $stmt = $this->pdo->prepare(
                'SELECT pe.plan_exercises_id, pe.user_id, pe.plan_day_id, pe.exercise_id,
                        pe.order_index, pe.target_sets, pe.target_reps_min, pe.target_reps_max,
                        pe.target_weight_kg, pe.target_rpe, pe.rest_seconds, pe.notes,
                        pe.created_at, pe.updated_at,
                        e.name AS exercise_name, e.measurement_type AS exercise_measurement_type,
                        e.primary_muscle AS exercise_primary_muscle,
                        e.primary_muscles AS exercise_primary_muscles,
                        e.secondary_muscles AS exercise_secondary_muscles
                 FROM mbc_gym_plan_exercises pe
                 INNER JOIN mbc_gym_exercises e ON e.exercises_id = pe.exercise_id
                 WHERE pe.plan_exercises_id = ?'
            );
            $stmt->execute([$peId]);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error updating gym plan exercise', $e);
        }
    }
}
