<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /grow/plant-devices?plant_id={id} — IoT-Geräte einer Pflanze inkl.
 * Sensor-Selbstbeschreibung und letztem Messwert.
 *
 * Ownership: Pflanze muss dem User gehören (IoT-Geräte sind global/netzwerk-scoped).
 */
class requestGetGrowPlantDevices extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');
            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $plantId = isset($this->request['plant_id']) ? (int)$this->request['plant_id'] : 0;
            if ($plantId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'plant_id is required']);
                return;
            }

            $stmt = $this->pdo->prepare('SELECT grow_plants_id FROM mbc_grow_plants WHERE grow_plants_id = ? AND user_id = ?');
            $stmt->execute([$plantId, $userId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Plant not found']);
                return;
            }

            $stmt = $this->pdo->prepare(
                'SELECT pd.grow_plant_devices_id, pd.plant_id, pd.device_id, pd.sensor_roles, pd.assigned_at,
                        d.name AS device_name, d.typ AS device_typ, d.online_status, d.last_heartbeat
                 FROM mbc_grow_plant_devices pd
                 INNER JOIN mbc_iot_devices d ON d.iot_devices_id = pd.device_id
                 WHERE pd.plant_id = ?
                 ORDER BY d.name ASC'
            );
            $stmt->execute([$plantId]);
            $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $sensorStmt = $this->pdo->prepare(
                'SELECT s.sensor_key, s.typ, s.einheit, s.modell,
                        (SELECT sd.wert FROM mbc_iot_sensor_data sd
                          WHERE sd.mbc_iot_devices = s.mbc_iot_devices AND sd.sensor_key = s.sensor_key
                          ORDER BY sd.zeitstempel DESC LIMIT 1) AS latest_wert,
                        (SELECT sd.zeitstempel FROM mbc_iot_sensor_data sd
                          WHERE sd.mbc_iot_devices = s.mbc_iot_devices AND sd.sensor_key = s.sensor_key
                          ORDER BY sd.zeitstempel DESC LIMIT 1) AS latest_zeitstempel
                 FROM mbc_iot_sensoren s
                 WHERE s.mbc_iot_devices = ?
                 ORDER BY s.sensor_key ASC'
            );

            foreach ($assignments as &$a) {
                $sensorStmt->execute([(int)$a['device_id']]);
                $a['sensors'] = $sensorStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            unset($a);

            echo json_encode($assignments);
        } catch (\Throwable $e) {
            $this->handleError('Error fetching plant devices', $e);
        }
    }
}
