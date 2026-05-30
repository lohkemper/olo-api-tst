<?php
declare(strict_types=1);
// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

if (!STOKEN) die('SEC');

/**
 * GET /iot/devices          — Alle registrierten Geräte
 * GET /iot/devices/{id}     — Einzelnes Gerät mit Sensoren und Aktoren
 *
 * Response-Format: camelCase (matched 1:1 das Frontend-Interface IotDevice
 * aus @olo/iot — kein Frontend-Mapper mehr nötig). Datums-Felder als
 * ISO-8601 mit Z (UTC). DB speichert UTC (siehe cfg.php).
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
                    d.mbc_iot_networks, d.online_status, d.last_heartbeat, d.registered_at,
                    n.name AS network_name, n.pi_local_ip
             FROM mbc_iot_devices d
             LEFT JOIN mbc_iot_networks n ON d.mbc_iot_networks = n.iot_networks_id
             ORDER BY d.name'
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $devices = [];
        foreach ($rows as $row) {
            $id = (int)$row['iot_devices_id'];

            $device = $this->mapDevice($row);

            $cnt = $this->pdo->prepare('SELECT COUNT(*) AS cnt FROM mbc_iot_sensoren WHERE mbc_iot_devices = ?');
            $cnt->execute([$id]);
            $device['sensorCount'] = (int)$cnt->fetch()['cnt'];

            $cnt = $this->pdo->prepare('SELECT COUNT(*) AS cnt FROM mbc_iot_aktoren WHERE mbc_iot_devices = ?');
            $cnt->execute([$id]);
            $device['aktorCount'] = (int)$cnt->fetch()['cnt'];

            $devices[] = $device;
        }

        echo json_encode($devices);
    }

    private function getDeviceById(int $deviceId): void {
        $stmt = $this->pdo->prepare(
            'SELECT d.iot_devices_id, d.chip_id, d.name, d.typ, d.firmware_version,
                    d.mbc_iot_networks, d.online_status, d.last_heartbeat, d.registered_at,
                    n.name AS network_name, n.pi_local_ip
             FROM mbc_iot_devices d
             LEFT JOIN mbc_iot_networks n ON d.mbc_iot_networks = n.iot_networks_id
             WHERE d.iot_devices_id = ?'
        );
        $stmt->execute([$deviceId]);
        $row = $stmt->fetch();

        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Device not found']);
            return;
        }

        $device = $this->mapDevice($row);

        // Sensoren
        $stmt = $this->pdo->prepare(
            'SELECT iot_sensoren_id, sensor_key, typ, einheit, modell, intervall_sekunden, mqtt_topic
             FROM mbc_iot_sensoren WHERE mbc_iot_devices = ?'
        );
        $stmt->execute([$deviceId]);
        $device['sensoren'] = array_map([$this, 'mapSensor'], $stmt->fetchAll());

        // Aktoren
        $stmt = $this->pdo->prepare(
            'SELECT iot_aktoren_id, aktor_key, typ
             FROM mbc_iot_aktoren WHERE mbc_iot_devices = ?'
        );
        $stmt->execute([$deviceId]);
        $device['aktoren'] = array_map([$this, 'mapAktor'], $stmt->fetchAll());

        echo json_encode($device);
    }

    /**
     * Device-Zeile (DB snake_case) → camelCase-Response (matched IotDevice).
     */
    private function mapDevice(array $row): array {
        return [
            'id'              => (int)$row['iot_devices_id'],
            'chipId'          => $row['chip_id'],
            'name'            => $row['name'],
            'typ'             => $row['typ'],
            'firmwareVersion' => $row['firmware_version'],
            'networkId'       => isset($row['mbc_iot_networks']) ? (int)$row['mbc_iot_networks'] : null,
            'networkName'     => $row['network_name'] ?? null,
            'piLocalIp'       => $row['pi_local_ip'] ?? null,
            'onlineStatus'    => $row['online_status'],
            'lastHeartbeat'   => $this->toIso8601($row['last_heartbeat'] ?? null),
            'registeredAt'    => $this->toIso8601($row['registered_at'] ?? null),
        ];
    }

    /**
     * Sensor-Zeile → camelCase (matched IotSensor).
     */
    private function mapSensor(array $r): array {
        return [
            'id'                => (int)$r['iot_sensoren_id'],
            'sensorKey'         => $r['sensor_key'],
            'typ'               => $r['typ'],
            'einheit'           => $r['einheit'],
            'modell'            => $r['modell'],
            'intervallSekunden' => isset($r['intervall_sekunden']) ? (int)$r['intervall_sekunden'] : null,
            'mqttTopic'         => $r['mqtt_topic'],
        ];
    }

    /**
     * Aktor-Zeile → camelCase (matched IotAktor).
     */
    private function mapAktor(array $r): array {
        return [
            'id'       => (int)$r['iot_aktoren_id'],
            'aktorKey' => $r['aktor_key'],
            'typ'      => $r['typ'],
        ];
    }

    /**
     * MySQL-DATETIME ("YYYY-MM-DD HH:MM:SS", UTC) → ISO-8601 mit Z.
     * Null-safe. Damit fällt der Date.parse(...replace(' ','T')+'Z')-
     * Workaround im Frontend weg.
     */
    private function toIso8601(?string $mysqlDateTime): ?string {
        if ($mysqlDateTime === null || $mysqlDateTime === '') {
            return null;
        }
        $ts = strtotime($mysqlDateTime);
        return $ts !== false ? gmdate('Y-m-d\TH:i:s\Z', $ts) : null;
    }
}
