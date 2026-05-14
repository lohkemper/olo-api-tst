<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /gym/cardio-sessions — Erfasst eine Cardio-Session.
 *
 * Body:
 *  {
 *    "activity_type": "running",      // pflicht
 *    "started_at": "2026-05-04 18:30:00",  // pflicht
 *    "duration_seconds": 2700,        // pflicht
 *    "distance_m": 5000,              // optional
 *    "avg_heartrate": 145,            // optional
 *    "max_heartrate": 168,            // optional
 *    "calories_kcal": 320,            // optional
 *    "elevation_gain_m": 45,          // optional
 *    "notes": "..."                   // optional
 *  }
 *
 * Server berechnet avg_pace_sec_per_km automatisch wenn distance_m + duration vorhanden.
 */
class requestPostGymCardioSessions extends RequestBase {
    private array $data = [];

    public function setData(array $data): void { $this->data = $data; }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $allowedActivities = ['running','cycling','swimming','rowing','walking','hiking','elliptical','other'];
            $activityType = (string)($this->data['activity_type'] ?? '');
            $startedAt    = (string)($this->data['started_at'] ?? '');
            $duration     = (int)($this->data['duration_seconds'] ?? 0);

            if (!in_array($activityType, $allowedActivities, true) || $startedAt === '' || $duration <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'activity_type, started_at and duration_seconds are required']);
                return;
            }

            $distanceM = isset($this->data['distance_m']) ? (float)$this->data['distance_m'] : null;
            $pace      = null;
            if ($distanceM !== null && $distanceM > 0) {
                $pace = (int)round(($duration / $distanceM) * 1000);
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO mbc_gym_cardio_sessions
                  (user_id, activity_type, started_at, duration_seconds,
                   distance_m, avg_heartrate, max_heartrate, calories_kcal,
                   avg_pace_sec_per_km, elevation_gain_m, source, external_id, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                $activityType,
                $startedAt,
                $duration,
                $distanceM,
                $this->data['avg_heartrate']    ?? null,
                $this->data['max_heartrate']    ?? null,
                $this->data['calories_kcal']    ?? null,
                $pace,
                $this->data['elevation_gain_m'] ?? null,
                (string)($this->data['source'] ?? 'manual'),
                $this->data['external_id']     ?? null,
                $this->data['notes']           ?? null,
            ]);

            $newId = (int)$this->pdo->lastInsertId();
            $stmt = $this->pdo->prepare(
                'SELECT cardio_sessions_id, user_id, activity_type, started_at,
                        duration_seconds, distance_m, avg_heartrate, max_heartrate,
                        calories_kcal, avg_pace_sec_per_km, elevation_gain_m,
                        source, external_id, notes, created_at, updated_at
                 FROM mbc_gym_cardio_sessions WHERE cardio_sessions_id = ?'
            );
            $stmt->execute([$newId]);
            http_response_code(201);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (\PDOException $e) {
            // External-ID Dedup-Konflikt
            if ($e->getCode() === '23000') {
                http_response_code(409);
                echo json_encode(['error' => 'Cardio session with this external_id already exists']);
                return;
            }
            $this->handleError('Error creating gym cardio session', $e);
        } catch (\Throwable $e) {
            $this->handleError('Error creating gym cardio session', $e);
        }
    }
}
