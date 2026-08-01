<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /gym/body-measurements                 — Liste (chronologisch DESC)
 * GET /gym/body-measurements/{id}            — einzelne Messung
 * GET /gym/body-measurements?from=YYYY-MM-DD&to=YYYY-MM-DD  — Bereich filtern
 */
class requestGetGymBodyMeasurements extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

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
            $this->handleError('Error fetching gym body measurements', $e);
        }
    }

    private function getList(int $userId): void {
        $sql = 'SELECT body_measurements_id, user_id, measured_at,
                       weight_kg, body_fat_pct, muscle_mass_kg,
                       chest_cm, waist_cm, hips_cm,
                       arm_left_cm, arm_right_cm,
                       thigh_left_cm, thigh_right_cm,
                       calf_left_cm, calf_right_cm, neck_cm,
                       notes, created_at, updated_at
                FROM mbc_gym_body_measurements
                WHERE user_id = ?';
        $params = [$userId];

        if (!empty($this->request['from'])) {
            $sql .= ' AND measured_at >= ?';
            $params[] = $this->request['from'];
        }
        if (!empty($this->request['to'])) {
            $sql .= ' AND measured_at <= ?';
            $params[] = $this->request['to'];
        }

        $sql .= ' ORDER BY measured_at DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function getById(int $userId, int $id): void {
        $stmt = $this->pdo->prepare(
            'SELECT body_measurements_id, user_id, measured_at,
                    weight_kg, body_fat_pct, muscle_mass_kg,
                    chest_cm, waist_cm, hips_cm,
                    arm_left_cm, arm_right_cm,
                    thigh_left_cm, thigh_right_cm,
                    calf_left_cm, calf_right_cm, neck_cm,
                    notes, created_at, updated_at
             FROM mbc_gym_body_measurements
             WHERE body_measurements_id = ? AND user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Body measurement not found']);
            return;
        }

        echo json_encode($row);
    }
}
