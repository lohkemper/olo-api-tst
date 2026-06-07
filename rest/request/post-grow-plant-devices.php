<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /grow/plant-devices — Ordnet ein IoT-Gerät einer Pflanze zu.
 *
 * Body: { "plant_id": 5, "device_id": 3, "sensor_roles": {...} (optional) }
 */
class requestPostGrowPlantDevices extends RequestBase {
    private array $data = [];

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');
            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $plantId = (int)($this->data['plant_id'] ?? 0);
            $deviceId = (int)($this->data['device_id'] ?? 0);
            if ($plantId <= 0 || $deviceId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'plant_id and device_id are required']);
                return;
            }

            // Pflanzen-Ownership
            $stmt = $this->pdo->prepare('SELECT grow_plants_id FROM mbc_grow_plants WHERE grow_plants_id = ? AND user_id = ?');
            $stmt->execute([$plantId, $userId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Plant not found']);
                return;
            }

            // Gerät existiert (global)
            $stmt = $this->pdo->prepare('SELECT iot_devices_id FROM mbc_iot_devices WHERE iot_devices_id = ?');
            $stmt->execute([$deviceId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Device not found']);
                return;
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO mbc_grow_plant_devices (plant_id, device_id, sensor_roles)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE sensor_roles = VALUES(sensor_roles)'
            );
            $stmt->execute([
                $plantId,
                $deviceId,
                isset($this->data['sensor_roles']) ? json_encode($this->data['sensor_roles']) : null,
            ]);

            $stmt = $this->pdo->prepare(
                'SELECT pd.grow_plant_devices_id, pd.plant_id, pd.device_id, pd.sensor_roles, pd.assigned_at,
                        d.name AS device_name, d.typ AS device_typ, d.online_status, d.last_heartbeat
                 FROM mbc_grow_plant_devices pd
                 INNER JOIN mbc_iot_devices d ON d.iot_devices_id = pd.device_id
                 WHERE pd.plant_id = ? AND pd.device_id = ?'
            );
            $stmt->execute([$plantId, $deviceId]);
            $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($assignment) {
                $assignment['sensors'] = [];
            }

            http_response_code(201);
            echo json_encode($assignment);
        } catch (\Throwable $e) {
            $this->handleError('Error assigning device to plant', $e);
        }
    }
}
