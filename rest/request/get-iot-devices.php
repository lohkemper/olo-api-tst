// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary
<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /iot/devices          — Alle registrierten Geräte
 * GET /iot/devices/{id}     — Einzelnes Gerät mit Sensoren und Aktoren
 */
class requestGetIotDevices extends RequestBase {
    private array $request = [];

    public function __construct(PDO $pdo, string $area) {
        parent::__construct($pdo, $area);
    }

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $deviceId = $this->request['id'] ?? null;

            if ($deviceId) {
                $this->getDeviceById((int)$deviceId);
            } else {
                $this->getAllDevices();
            }

        } catch (\Throwable $e) {
            $this->handleError('Error fetching IoT devices', $e);
        }
    }

    private function getAllDevices(): void {
        $stmt = $this->pdo->prepare(
            'SELECT d.iot_devices_id, d.chip_id, d.name, d.typ, d.firmware_version,
                    d.online_status, d.last_heartbeat, d.registered_at,
                    n.name AS network_name, n.pi_local_ip
             FROM mbc_iot_devices d
             LEFT JOIN mbc_iot_networks n ON d.mbc_iot_networks = n.iot_networks_id
             ORDER BY d.name'
        );
        $stmt->execute();
        $devices = $stmt->fetchAll();

        // Add sensor/actor counts
        foreach ($devices as &$device) {
            $id = $device['iot_devices_id'];

            $stmt = $this->pdo->prepare('SELECT COUNT(*) AS cnt FROM mbc_iot_sensoren WHERE mbc_iot_devices = ?');
            $stmt->execute([$id]);
            $device['sensor_count'] = (int)$stmt->fetch()['cnt'];

            $stmt = $this->pdo->prepare('SELECT COUNT(*) AS cnt FROM mbc_iot_aktoren WHERE mbc_iot_devices = ?');
            $stmt->execute([$id]);
            $device['aktor_count'] = (int)$stmt->fetch()['cnt'];
        }

        echo json_encode($devices);
    }

    private function getDeviceById(int $deviceId): void {
        // Fetch device
        $stmt = $this->pdo->prepare(
            'SELECT d.iot_devices_id, d.chip_id, d.name, d.typ, d.firmware_version,
                    d.online_status, d.last_heartbeat, d.registered_at,
                    n.name AS network_name, n.pi_local_ip, n.iot_ssid, n.mqtt_port
             FROM mbc_iot_devices d
             LEFT JOIN mbc_iot_networks n ON d.mbc_iot_networks = n.iot_networks_id
             WHERE d.iot_devices_id = ?'
        );
        $stmt->execute([$deviceId]);
        $device = $stmt->fetch();

        if (!$device) {
            http_response_code(404);
            echo json_encode(['error' => 'Device not found']);
            return;
        }

        // Fetch sensors
        $stmt = $this->pdo->prepare(
            'SELECT iot_sensoren_id, sensor_key, typ, einheit, modell, intervall_sekunden, mqtt_topic
             FROM mbc_iot_sensoren WHERE mbc_iot_devices = ?'
        );
        $stmt->execute([$deviceId]);
        $device['sensoren'] = $stmt->fetchAll();

        // Fetch actors
        $stmt = $this->pdo->prepare(
            'SELECT iot_aktoren_id, aktor_key, typ, name, zustaende, mqtt_topic_set, mqtt_topic_status
             FROM mbc_iot_aktoren WHERE mbc_iot_devices = ?'
        );
        $stmt->execute([$deviceId]);
        $aktoren = $stmt->fetchAll();

        // Decode JSON zustaende
        foreach ($aktoren as &$aktor) {
            $aktor['zustaende'] = json_decode($aktor['zustaende'], true);
        }
        $device['aktoren'] = $aktoren;

        echo json_encode($device);
    }
}
