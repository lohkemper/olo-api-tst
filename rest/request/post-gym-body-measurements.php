<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /gym/body-measurements — Erfasst (oder ersetzt) eine Messung pro Tag.
 *
 * Body (alle Werte optional außer measured_at):
 *  {
 *    "measured_at": "2026-05-04",
 *    "weight_kg": 82.5,
 *    "height_cm": 183.0,
 *    "body_fat_pct": 14.2,
 *    "muscle_mass_kg": 38.1,
 *    "chest_cm": 105.0,
 *    "waist_cm": 81.0,
 *    "hips_cm": 98.0,
 *    "arm_left_cm": 38.5,
 *    "arm_right_cm": 38.7,
 *    "thigh_left_cm": 60.0,
 *    "thigh_right_cm": 60.2,
 *    "calf_left_cm": 38.0,
 *    "calf_right_cm": 38.0,
 *    "neck_cm": 39.0,
 *    "notes": "..."
 *  }
 *
 * UNIQUE-Constraint (user_id, measured_at) — bei Duplikat führen wir
 * stattdessen ein UPDATE durch (Upsert), um den User vor dem 409 zu schützen.
 */
class requestPostGymBodyMeasurements extends RequestBase {
    private array $data = [];

    public function setData(array $data): void { $this->data = $data; }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $measuredAt = trim((string)($this->data['measured_at'] ?? ''));
            if ($measuredAt === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $measuredAt)) {
                http_response_code(400);
                echo json_encode(['error' => 'measured_at (YYYY-MM-DD) is required']);
                return;
            }

            $cols = [
                'weight_kg','height_cm','body_fat_pct','muscle_mass_kg',
                'chest_cm','waist_cm','hips_cm',
                'arm_left_cm','arm_right_cm',
                'thigh_left_cm','thigh_right_cm',
                'calf_left_cm','calf_right_cm','neck_cm',
                'notes',
            ];

            $insertCols = ['user_id', 'measured_at'];
            $insertVals = [$userId, $measuredAt];
            $updateAssign = [];

            foreach ($cols as $c) {
                if (array_key_exists($c, $this->data)) {
                    $insertCols[] = $c;
                    $insertVals[] = $this->data[$c];
                    $updateAssign[] = "$c = VALUES($c)";
                }
            }

            $placeholders = implode(',', array_fill(0, count($insertVals), '?'));
            $sql = 'INSERT INTO mbc_gym_body_measurements (' . implode(',', $insertCols) . ') '
                 . 'VALUES (' . $placeholders . ')';
            if (!empty($updateAssign)) {
                $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updateAssign);
            } else {
                // Kein einziger Wert — wir lassen den existierenden Datensatz unverändert.
                $sql .= ' ON DUPLICATE KEY UPDATE measured_at = VALUES(measured_at)';
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($insertVals);

            $stmt = $this->pdo->prepare(
                'SELECT body_measurements_id, user_id, measured_at,
                        weight_kg, height_cm, body_fat_pct, muscle_mass_kg,
                        chest_cm, waist_cm, hips_cm,
                        arm_left_cm, arm_right_cm,
                        thigh_left_cm, thigh_right_cm,
                        calf_left_cm, calf_right_cm, neck_cm,
                        notes, created_at, updated_at
                 FROM mbc_gym_body_measurements
                 WHERE user_id = ? AND measured_at = ?
                 LIMIT 1'
            );
            $stmt->execute([$userId, $measuredAt]);
            http_response_code(201);
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error creating gym body measurement', $e);
        }
    }
}
