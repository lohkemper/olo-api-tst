<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /gym/personal-records                — Alle PRs des Users (mit exercise_name)
 * GET /gym/personal-records?exercise_id=X  — Filter nach Übung
 */
class requestGetGymPersonalRecords extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $exerciseId = isset($this->request['exercise_id']) ? (int)$this->request['exercise_id'] : null;

            $sql = 'SELECT pr.personal_records_id, pr.user_id, pr.exercise_id, pr.record_type,
                           pr.value, pr.reference_weight_kg, pr.workout_set_id, pr.workout_id,
                           pr.achieved_at, pr.created_at, pr.updated_at,
                           e.name AS exercise_name, e.primary_muscle AS exercise_primary_muscle,
                           e.measurement_type AS exercise_measurement_type
                    FROM mbc_gym_personal_records pr
                    INNER JOIN mbc_gym_exercises e ON e.exercises_id = pr.exercise_id
                    WHERE pr.user_id = ?';
            $params = [$userId];

            if ($exerciseId !== null) {
                $sql .= ' AND pr.exercise_id = ?';
                $params[] = $exerciseId;
            }

            $sql .= ' ORDER BY e.name ASC, pr.record_type ASC, pr.reference_weight_kg ASC';

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error fetching gym personal records', $e);
        }
    }
}
