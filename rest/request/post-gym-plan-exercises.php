<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /gym/plan-exercises — Fügt eine Übung zu einem Plan-Tag hinzu.
 *
 * Body:
 *  {
 *    "plan_day_id": 7,
 *    "exercise_id": 12,
 *    "order_index": 1,            // optional, autom. nächster
 *    "target_sets": 4,
 *    "target_reps_min": 6,
 *    "target_reps_max": 10,
 *    "target_weight_kg": 80.0,
 *    "target_rpe": 8.5,
 *    "rest_seconds": 120,
 *    "notes": "Tempo 3-1-1"
 *  }
 */
class requestPostGymPlanExercises extends RequestBase {
    private array $data = [];

    public function setData(array $data): void { $this->data = $data; }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $planDayId  = (int)($this->data['plan_day_id'] ?? 0);
            $exerciseId = (int)($this->data['exercise_id'] ?? 0);
            if ($planDayId <= 0 || $exerciseId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'plan_day_id and exercise_id are required']);
                return;
            }

            // Plan-Day-Ownership prüfen
            $stmt = $this->pdo->prepare(
                'SELECT plan_days_id FROM mbc_gym_plan_days WHERE plan_days_id = ? AND user_id = ?'
            );
            $stmt->execute([$planDayId, $userId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Plan-day not found']);
                return;
            }

            // Exercise sichtbar (Template oder eigen)?
            $stmt = $this->pdo->prepare(
                'SELECT exercises_id FROM mbc_gym_exercises
                 WHERE exercises_id = ? AND (user_id IS NULL OR user_id = ?)'
            );
            $stmt->execute([$exerciseId, $userId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Exercise not visible to this user']);
                return;
            }

            // order_index autom. ermitteln
            $orderIndex = $this->data['order_index'] ?? null;
            if ($orderIndex === null) {
                $stmt = $this->pdo->prepare(
                    'SELECT COALESCE(MAX(order_index), 0) + 1 AS next_idx
                     FROM mbc_gym_plan_exercises WHERE plan_day_id = ?'
                );
                $stmt->execute([$planDayId]);
                $orderIndex = (int)$stmt->fetch(PDO::FETCH_ASSOC)['next_idx'];
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO mbc_gym_plan_exercises
                  (user_id, plan_day_id, exercise_id, order_index, target_sets,
                   target_reps_min, target_reps_max, target_weight_kg, target_rpe,
                   rest_seconds, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                $planDayId,
                $exerciseId,
                (int)$orderIndex,
                $this->data['target_sets']      ?? null,
                $this->data['target_reps_min']  ?? null,
                $this->data['target_reps_max']  ?? null,
                $this->data['target_weight_kg'] ?? null,
                $this->data['target_rpe']       ?? null,
                $this->data['rest_seconds']     ?? null,
                $this->data['notes']            ?? null,
            ]);

            $newId = (int)$this->pdo->lastInsertId();
            $stmt = $this->pdo->prepare(
                'SELECT pe.plan_exercises_id, pe.user_id, pe.plan_day_id, pe.exercise_id,
                        pe.order_index, pe.target_sets, pe.target_reps_min, pe.target_reps_max,
                        pe.target_weight_kg, pe.target_rpe, pe.rest_seconds, pe.notes,
                        pe.created_at, pe.updated_at,
                        e.name AS exercise_name, e.measurement_type AS exercise_measurement_type,
                        e.primary_muscle AS exercise_primary_muscle
                 FROM mbc_gym_plan_exercises pe
                 INNER JOIN mbc_gym_exercises e ON e.exercises_id = pe.exercise_id
                 WHERE pe.plan_exercises_id = ?'
            );
            $stmt->execute([$newId]);
            http_response_code(201);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error creating gym plan exercise', $e);
        }
    }
}
