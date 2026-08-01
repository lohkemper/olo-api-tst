<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /gym/cardio-sessions               — Liste DESC nach started_at
 * GET /gym/cardio-sessions/{id}          — Detail
 * GET /gym/cardio-sessions?from=…&to=…   — Bereich filtern
 */
class requestGetGymCardioSessions extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void { $this->request = $request; }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $id = isset($this->request['id']) ? (int)$this->request['id'] : null;

            if ($id !== null) {
                $this->getById($userId, $id);
            } else {
                $this->getList($userId);
            }
        } catch (\Throwable $e) {
            $this->handleError('Error fetching gym cardio sessions', $e);
        }
    }

    private function getList(int $userId): void {
        $sql = 'SELECT cardio_sessions_id, user_id, activity_type, started_at,
                       duration_seconds, distance_m, avg_heartrate, max_heartrate,
                       calories_kcal, avg_pace_sec_per_km, elevation_gain_m,
                       source, external_id, notes, created_at, updated_at
                FROM mbc_gym_cardio_sessions
                WHERE user_id = ?';
        $params = [$userId];

        if (!empty($this->request['from'])) {
            $sql .= ' AND started_at >= ?';
            $params[] = $this->request['from'];
        }
        if (!empty($this->request['to'])) {
            $sql .= ' AND started_at <= ?';
            $params[] = $this->request['to'];
        }

        $limit  = max(1, min(200, (int)($this->request['limit']  ?? 50)));
        $offset = max(0, (int)($this->request['offset'] ?? 0));
        $sql .= ' ORDER BY started_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function getById(int $userId, int $id): void {
        $stmt = $this->pdo->prepare(
            'SELECT cardio_sessions_id, user_id, activity_type, started_at,
                    duration_seconds, distance_m, avg_heartrate, max_heartrate,
                    calories_kcal, avg_pace_sec_per_km, elevation_gain_m,
                    source, external_id, notes, created_at, updated_at
             FROM mbc_gym_cardio_sessions
             WHERE cardio_sessions_id = ? AND user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Cardio session not found']);
            return;
        }

        echo json_encode($row);
    }
}
