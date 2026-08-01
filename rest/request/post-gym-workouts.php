<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /gym/workouts — Startet ein neues Workout für den eingeloggten User
 *
 * Body (alles optional):
 *  {
 *    "name": "Push Day A",
 *    "started_at": "2026-05-03 18:30:00",   // default: now
 *    "plan_day_id": 7,                      // Phase 2
 *    "notes": "...",
 *    "body_weight_kg": 82.5
 *  }
 *
 * Response: 201 + Workout-Objekt
 */
class requestPostGymWorkouts extends RequestBase {
    private array $data = [];

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $startedAt = $this->data['started_at'] ?? date('Y-m-d H:i:s');

            $stmt = $this->pdo->prepare(
                'INSERT INTO mbc_gym_workouts
                   (user_id, plan_day_id, started_at, name, notes, body_weight_kg)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                $this->data['plan_day_id']    ?? null,
                $startedAt,
                $this->data['name']           ?? null,
                $this->data['notes']          ?? null,
                $this->data['body_weight_kg'] ?? null,
            ]);

            $newId = (int)$this->pdo->lastInsertId();

            $stmt = $this->pdo->prepare(
                'SELECT workouts_id, user_id, plan_day_id, started_at, ended_at, name,
                        notes, body_weight_kg, total_volume_kg, duration_seconds,
                        created_at, updated_at
                 FROM mbc_gym_workouts WHERE workouts_id = ?'
            );
            $stmt->execute([$newId]);
            http_response_code(201);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error creating gym workout', $e);
        }
    }
}
