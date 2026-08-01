<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /gym/plans          — Liste der eigenen Pläne (mit Day-Counts)
 * GET /gym/plans/{id}     — Plan-Detail inklusive Days und Plan-Exercises
 */
class requestGetGymPlans extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $planId = isset($this->request['id']) ? (int)$this->request['id'] : null;

            if ($planId !== null) {
                $this->getDetail($userId, $planId);
            } else {
                $this->getList($userId);
            }
        } catch (\Throwable $e) {
            $this->handleError('Error fetching gym plans', $e);
        }
    }

    private function getList(int $userId): void {
        $stmt = $this->pdo->prepare(
            'SELECT p.plans_id, p.user_id, p.name, p.description, p.goal, p.weeks, p.is_active,
                    p.created_at, p.updated_at,
                    (SELECT COUNT(*) FROM mbc_gym_plan_days d WHERE d.plan_id = p.plans_id) AS day_count
             FROM mbc_gym_plans p
             WHERE p.user_id = ?
             ORDER BY p.is_active DESC, p.name ASC'
        );
        $stmt->execute([$userId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function getDetail(int $userId, int $planId): void {
        $stmt = $this->pdo->prepare(
            'SELECT plans_id, user_id, name, description, goal, weeks, is_active,
                    created_at, updated_at
             FROM mbc_gym_plans
             WHERE plans_id = ? AND user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$planId, $userId]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$plan) {
            http_response_code(404);
            echo json_encode(['error' => 'Plan not found']);
            return;
        }

        $stmt = $this->pdo->prepare(
            'SELECT plan_days_id, user_id, plan_id, day_index, name, notes, created_at, updated_at
             FROM mbc_gym_plan_days
             WHERE plan_id = ? AND user_id = ?
             ORDER BY day_index ASC'
        );
        $stmt->execute([$planId, $userId]);
        $days = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($days)) {
            $dayIds = array_column($days, 'plan_days_id');
            $placeholders = implode(',', array_fill(0, count($dayIds), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT pe.plan_exercises_id, pe.user_id, pe.plan_day_id, pe.exercise_id,
                        pe.order_index, pe.target_sets, pe.target_reps_min, pe.target_reps_max,
                        pe.target_weight_kg, pe.target_rpe, pe.rest_seconds, pe.notes,
                        pe.created_at, pe.updated_at,
                        e.name AS exercise_name, e.measurement_type AS exercise_measurement_type,
                        e.primary_muscle AS exercise_primary_muscle
                 FROM mbc_gym_plan_exercises pe
                 INNER JOIN mbc_gym_exercises e ON e.exercises_id = pe.exercise_id
                 WHERE pe.plan_day_id IN ($placeholders) AND pe.user_id = ?
                 ORDER BY pe.plan_day_id ASC, pe.order_index ASC"
            );
            $stmt->execute([...$dayIds, $userId]);
            $allExercises = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $byDay = [];
            foreach ($allExercises as $ex) {
                $byDay[$ex['plan_day_id']][] = $ex;
            }
            foreach ($days as &$day) {
                $day['exercises'] = $byDay[$day['plan_days_id']] ?? [];
            }
        } else {
            foreach ($days as &$day) { $day['exercises'] = []; }
        }

        $plan['days'] = $days;
        echo json_encode($plan);
    }
}
