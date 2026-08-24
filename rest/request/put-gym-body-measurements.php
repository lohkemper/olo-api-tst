<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * PUT /gym/body-measurements/{id} — Aktualisiert einzelne Felder einer Messung.
 */
class requestPutGymBodyMeasurements extends RequestBase {
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
                echo json_encode(['error' => 'Body-measurement id required']);
                return;
            }

            $allowed = [
                'measured_at',
                'weight_kg','height_cm','body_fat_pct','muscle_mass_kg',
                'chest_cm','waist_cm','hips_cm',
                'arm_left_cm','arm_right_cm',
                'thigh_left_cm','thigh_right_cm',
                'calf_left_cm','calf_right_cm','neck_cm',
                'notes',
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

            $params[] = $id;
            $params[] = $userId;
            $sql = 'UPDATE mbc_gym_body_measurements SET ' . implode(', ', $sets)
                 . ' WHERE body_measurements_id = ? AND user_id = ?';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Body-measurement not found']);
                return;
            }

            $stmt = $this->pdo->prepare(
                'SELECT body_measurements_id, user_id, measured_at,
                        weight_kg, height_cm, body_fat_pct, muscle_mass_kg,
                        chest_cm, waist_cm, hips_cm,
                        arm_left_cm, arm_right_cm,
                        thigh_left_cm, thigh_right_cm,
                        calf_left_cm, calf_right_cm, neck_cm,
                        notes, created_at, updated_at
                 FROM mbc_gym_body_measurements WHERE body_measurements_id = ?'
            );
            $stmt->execute([$id]);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error updating gym body measurement', $e);
        }
    }
}
