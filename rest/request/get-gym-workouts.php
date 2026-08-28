<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /gym/workouts            — Liste aller Workouts (eigene, paginierbar via ?limit=N&offset=N)
 * GET /gym/workouts/{id}       — Einzelnes Workout inklusive aller Sets
 */
class requestGetGymWorkouts extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $workoutId = isset($this->request['id']) ? (int)$this->request['id'] : null;

            if ($workoutId !== null) {
                $this->getDetail($userId, $workoutId);
            } else {
                $this->getList($userId);
            }
        } catch (\Throwable $e) {
            $this->handleError('Error fetching gym workouts', $e);
        }
    }

    private function getList(int $userId): void {
        $limit  = max(1, min(200, (int)($this->request['limit']  ?? 50)));
        $offset = max(0, (int)($this->request['offset'] ?? 0));

        // Aktive Sessions zuerst (ended_at IS NULL), dann nach started_at DESC.
        // plan_day_split + set_muscles speisen das Split-Segment der History:
        // der Client nimmt den Plan-Split, sonst leitet er aus den Muskeln ab.
        $sql = 'SELECT w.workouts_id, w.user_id, w.plan_day_id, w.started_at, w.ended_at, w.name,
                       w.notes, w.body_weight_kg, w.total_volume_kg, w.duration_seconds,
                       w.created_at, w.updated_at,
                       pd.split AS plan_day_split,
                       (SELECT GROUP_CONCAT(DISTINCT e.primary_muscle)
                          FROM mbc_gym_workout_sets s
                          INNER JOIN mbc_gym_exercises e ON e.exercises_id = s.exercise_id
                         WHERE s.workout_id = w.workouts_id
                           AND s.is_warmup = 0
                           AND e.primary_muscle IS NOT NULL AND e.primary_muscle <> \'\'
                       ) AS set_muscles
                FROM mbc_gym_workouts w
                LEFT JOIN mbc_gym_plan_days pd ON pd.plan_days_id = w.plan_day_id
                WHERE w.user_id = ?
                ORDER BY (w.ended_at IS NULL) DESC, w.started_at DESC
                LIMIT ' . $limit . ' OFFSET ' . $offset;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function getDetail(int $userId, int $workoutId): void {
        $stmt = $this->pdo->prepare(
            'SELECT w.workouts_id, w.user_id, w.plan_day_id, w.started_at, w.ended_at, w.name,
                    w.notes, w.body_weight_kg, w.total_volume_kg, w.duration_seconds,
                    w.created_at, w.updated_at,
                    pd.split AS plan_day_split,
                    (SELECT GROUP_CONCAT(DISTINCT e.primary_muscle)
                       FROM mbc_gym_workout_sets s
                       INNER JOIN mbc_gym_exercises e ON e.exercises_id = s.exercise_id
                      WHERE s.workout_id = w.workouts_id
                        AND s.is_warmup = 0
                        AND e.primary_muscle IS NOT NULL AND e.primary_muscle <> \'\'
                    ) AS set_muscles
             FROM mbc_gym_workouts w
             LEFT JOIN mbc_gym_plan_days pd ON pd.plan_days_id = w.plan_day_id
             WHERE w.workouts_id = ? AND w.user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$workoutId, $userId]);
        $workout = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$workout) {
            http_response_code(404);
            echo json_encode(['error' => 'Workout not found']);
            return;
        }

        $stmt = $this->pdo->prepare(
            'SELECT workout_sets_id, user_id, workout_id, exercise_id, set_index,
                    reps, weight_kg, time_seconds, distance_m, rpe,
                    is_warmup, is_failure, notes, performed_at, created_at, updated_at
             FROM mbc_gym_workout_sets
             WHERE workout_id = ? AND user_id = ?
             ORDER BY performed_at ASC, workout_sets_id ASC'
        );
        $stmt->execute([$workoutId, $userId]);
        $workout['sets'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($workout);
    }
}
