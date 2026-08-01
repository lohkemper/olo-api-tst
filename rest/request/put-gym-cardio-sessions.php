<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * PUT /gym/cardio-sessions/{id} — Aktualisiert eine Cardio-Session.
 *
 * Wenn distance_m oder duration_seconds geändert wird, wird avg_pace
 * automatisch neu berechnet (wenn beide vorhanden).
 */
class requestPutGymCardioSessions extends RequestBase {
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
                echo json_encode(['error' => 'Cardio-session id required']);
                return;
            }

            // Bestehender Datensatz für Pace-Recompute
            $stmt = $this->pdo->prepare(
                'SELECT distance_m, duration_seconds FROM mbc_gym_cardio_sessions
                 WHERE cardio_sessions_id = ? AND user_id = ?'
            );
            $stmt->execute([$id, $userId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                http_response_code(404);
                echo json_encode(['error' => 'Cardio session not found']);
                return;
            }

            $allowed = [
                'activity_type','started_at','duration_seconds','distance_m',
                'avg_heartrate','max_heartrate','calories_kcal','elevation_gain_m',
                'source','external_id','notes',
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

            // Pace neu berechnen
            $newDistance = array_key_exists('distance_m', $this->data) ? $this->data['distance_m'] : $existing['distance_m'];
            $newDuration = array_key_exists('duration_seconds', $this->data) ? $this->data['duration_seconds'] : $existing['duration_seconds'];
            if ($newDistance !== null && (float)$newDistance > 0 && $newDuration !== null && (int)$newDuration > 0) {
                $sets[] = 'avg_pace_sec_per_km = ?';
                $params[] = (int)round(((int)$newDuration / (float)$newDistance) * 1000);
            } elseif (array_key_exists('distance_m', $this->data) && ($newDistance === null || (float)$newDistance == 0)) {
                $sets[] = 'avg_pace_sec_per_km = NULL';
            }

            $params[] = $id;
            $params[] = $userId;
            $sql = 'UPDATE mbc_gym_cardio_sessions SET ' . implode(', ', $sets)
                 . ' WHERE cardio_sessions_id = ? AND user_id = ?';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            $stmt = $this->pdo->prepare(
                'SELECT cardio_sessions_id, user_id, activity_type, started_at,
                        duration_seconds, distance_m, avg_heartrate, max_heartrate,
                        calories_kcal, avg_pace_sec_per_km, elevation_gain_m,
                        source, external_id, notes, created_at, updated_at
                 FROM mbc_gym_cardio_sessions WHERE cardio_sessions_id = ?'
            );
            $stmt->execute([$id]);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error updating gym cardio session', $e);
        }
    }
}
