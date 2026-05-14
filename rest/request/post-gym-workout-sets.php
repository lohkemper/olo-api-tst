<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /gym/workout-sets — Erfasst ein neues Set in einem aktiven Workout.
 *
 * Body:
 *  {
 *    "workout_id": 42,
 *    "exercise_id": 7,
 *    "set_index": 1,                  // optional, default = nächster Index für die Übung
 *    "reps": 8,
 *    "weight_kg": 80.0,
 *    "time_seconds": null,
 *    "distance_m": null,
 *    "rpe": 8.5,
 *    "is_warmup": false,
 *    "is_failure": false,
 *    "notes": "...",
 *    "performed_at": "2026-05-03 18:31:42"   // optional, default = now
 *  }
 *
 * Response: 201 + Set-Objekt
 */
class requestPostGymWorkoutSets extends RequestBase {
    private array $data = [];

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $workoutId  = (int)($this->data['workout_id']  ?? 0);
            $exerciseId = (int)($this->data['exercise_id'] ?? 0);
            if ($workoutId <= 0 || $exerciseId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'workout_id and exercise_id are required']);
                return;
            }

            // Workout-Ownership prüfen + dass die Session aktiv ist
            $stmt = $this->pdo->prepare(
                'SELECT ended_at FROM mbc_gym_workouts WHERE workouts_id = ? AND user_id = ?'
            );
            $stmt->execute([$workoutId, $userId]);
            $workout = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$workout) {
                http_response_code(404);
                echo json_encode(['error' => 'Workout not found']);
                return;
            }
            if ($workout['ended_at'] !== null) {
                http_response_code(409);
                echo json_encode(['error' => 'Cannot add sets to a finished workout']);
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

            // set_index automatisch ermitteln, falls nicht übergeben
            $setIndex = $this->data['set_index'] ?? null;
            if ($setIndex === null) {
                $stmt = $this->pdo->prepare(
                    'SELECT COALESCE(MAX(set_index), 0) + 1 AS next_idx
                     FROM mbc_gym_workout_sets WHERE workout_id = ? AND exercise_id = ?'
                );
                $stmt->execute([$workoutId, $exerciseId]);
                $setIndex = (int)$stmt->fetch(PDO::FETCH_ASSOC)['next_idx'];
            }

            $performedAt = $this->data['performed_at'] ?? date('Y-m-d H:i:s');

            $stmt = $this->pdo->prepare(
                'INSERT INTO mbc_gym_workout_sets
                  (user_id, workout_id, exercise_id, set_index, reps, weight_kg,
                   time_seconds, distance_m, rpe, is_warmup, is_failure, notes, performed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                $workoutId,
                $exerciseId,
                (int)$setIndex,
                $this->data['reps']         ?? null,
                $this->data['weight_kg']    ?? null,
                $this->data['time_seconds'] ?? null,
                $this->data['distance_m']   ?? null,
                $this->data['rpe']          ?? null,
                !empty($this->data['is_warmup'])  ? 1 : 0,
                !empty($this->data['is_failure']) ? 1 : 0,
                $this->data['notes']        ?? null,
                $performedAt,
            ]);

            $newId = (int)$this->pdo->lastInsertId();
            $stmt = $this->pdo->prepare(
                'SELECT workout_sets_id, user_id, workout_id, exercise_id, set_index,
                        reps, weight_kg, time_seconds, distance_m, rpe,
                        is_warmup, is_failure, notes, performed_at, created_at, updated_at
                 FROM mbc_gym_workout_sets WHERE workout_sets_id = ?'
            );
            $stmt->execute([$newId]);
            http_response_code(201);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error creating gym workout set', $e);
        }
    }
}
