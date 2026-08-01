<?php
declare(strict_types=1);
// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

if (!STOKEN) die('SEC');

require_once __DIR__ . '/../lib/DeviceKey.php';

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
            $this->requireAuth();
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
                    d.mbc_iot_networks, d.online_status, d.provisioning_status, d.approved_at,
                    d.last_heartbeat, d.registered_at,
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
                    d.mbc_iot_networks, d.online_status, d.provisioning_status, d.approved_at,
                    d.last_heartbeat, d.registered_at,
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

        // Sensoren inkl. letztem Messwert (B16): Subqueries auf die Sensordaten,
        // jüngster Wert je sensor_key.
        $stmt = $this->pdo->prepare(
            'SELECT s.iot_sensoren_id, s.sensor_key, s.typ, s.einheit, s.modell,
                    s.intervall_sekunden, s.mqtt_topic,
                    (SELECT sd.wert FROM mbc_iot_sensor_data sd
                      WHERE sd.mbc_iot_devices = s.mbc_iot_devices AND sd.sensor_key = s.sensor_key
                      ORDER BY sd.zeitstempel DESC LIMIT 1) AS latest_wert,
                    (SELECT sd.zeitstempel FROM mbc_iot_sensor_data sd
                      WHERE sd.mbc_iot_devices = s.mbc_iot_devices AND sd.sensor_key = s.sensor_key
                      ORDER BY sd.zeitstempel DESC LIMIT 1) AS latest_zeitstempel
             FROM mbc_iot_sensoren s WHERE s.mbc_iot_devices = ?'
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

    /** Online nur, wenn der letzte Heartbeat innerhalb dieses Fensters liegt (Sekunden). */
    private const ONLINE_THRESHOLD_SECONDS = 300;

    /**
     * Device-Zeile (DB snake_case) → camelCase-Response (matched IotDevice).
     */
    private function mapDevice(array $row): array {
        return [
            'id'              => (int)$row['iot_devices_id'],
            'chipId'          => $row['chip_id'],
            'deviceKey'       => $this->deriveDeviceKey((int)$row['iot_devices_id']),
            'name'            => $row['name'],
            'typ'             => $row['typ'],
            'firmwareVersion' => $row['firmware_version'],
            'networkId'       => isset($row['mbc_iot_networks']) ? (int)$row['mbc_iot_networks'] : null,
            'networkName'     => $row['network_name'] ?? null,
            'piLocalIp'       => $row['pi_local_ip'] ?? null,
            'onlineStatus'    => $this->computeOnlineStatus($row['last_heartbeat'] ?? null),
            'provisioningStatus' => $row['provisioning_status'] ?? null,
            'approvedAt'      => $this->toIso8601($row['approved_at'] ?? null),
            'lastHeartbeat'   => $this->toIso8601($row['last_heartbeat'] ?? null),
            'registeredAt'    => $this->toIso8601($row['registered_at'] ?? null),
        ];
    }

    /**
     * Leitet den `deviceKey` (z.B. "garten/klima") aus dem MQTT-Topic-Schema
     * `pks/{projekt}/{bereich}/{messwert}` ab — die mittleren zwei Pfadteile
     * eines beliebigen Sensor- oder Aktor-Topics des Geräts.
     *
     * Der Pi-Sync pflegt damit seine lokale `chip_id_map` (device_key → chip_id),
     * ohne dass pro Gerät manuelles SQL nötig wird (STORY-1.8). Fehlt das Feld,
     * fällt der Pi auf eine Heuristik zurück, die den letzten Pfadteil als
     * chip_id nimmt — der Server lehnt die Messwerte dann als „Unknown chip_id"
     * ab, wie am 2026-04-26 über 70 Minuten geschehen.
     *
     * Geräte ohne Sensoren und Aktoren (z.B. der Pi selbst) liefern null.
     *
     * Wiederhergestellt aus dem nie gemergten Commit 1b2d7b9 (STORY-1.11).
     * Die Ableitung selbst liegt seit 2026-07-28 in `DeviceKey` — sie muss mit
     * `topics.Parse` auf der Pi-Seite übereinstimmen, und zwei Kopien derselben
     * Regel in zwei Dateien tun das erfahrungsgemäß nicht lange.
     */
    private function deriveDeviceKey(int $deviceId): ?string {
        // Alle Kandidaten in der Reihenfolge, die auch der Pi verwendet:
        // Sensoren nach ID, dann Aktoren nach ID. Vorher stand hier ein
        // `LIMIT 1` auf den Sensoren — war dessen Topic unbrauchbar, lieferte
        // die Ableitung null, obwohl ein zweiter Sensor gereicht hätte.
        $stmt = $this->pdo->prepare(
            'SELECT topic FROM (
                 SELECT mqtt_topic AS topic, 0 AS quelle, iot_sensoren_id AS pos
                   FROM mbc_iot_sensoren WHERE mbc_iot_devices = :dev
                 UNION ALL
                 SELECT mqtt_topic_set AS topic, 1 AS quelle, iot_aktoren_id AS pos
                   FROM mbc_iot_aktoren WHERE mbc_iot_devices = :dev2
             ) kandidaten
             ORDER BY quelle, pos'
        );
        $stmt->execute([':dev' => $deviceId, ':dev2' => $deviceId]);

        return DeviceKey::fromTopics($stmt->fetchAll(PDO::FETCH_COLUMN, 0));
    }

    /**
     * Leitet den Online-Status aus dem Alter des letzten Heartbeats ab (B14).
     * Die gespeicherte `online_status`-Spalte wird beim Heartbeat zwar auf
     * 'online' gesetzt, aber nie auf 'offline' zurückgesetzt — daher würde sie
     * dauerhaft 'online' anzeigen. Verlässlich ist allein `last_heartbeat`.
     */
    private function computeOnlineStatus(?string $lastHeartbeat): string {
        if (empty($lastHeartbeat)) {
            return 'offline';
        }
        $ts = strtotime($lastHeartbeat);
        if ($ts === false) {
            return 'offline';
        }
        return (time() - $ts) <= self::ONLINE_THRESHOLD_SECONDS ? 'online' : 'offline';
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
            'latestValue'       => isset($r['latest_wert']) && $r['latest_wert'] !== null ? (float)$r['latest_wert'] : null,
            'latestAt'          => $this->toIso8601($r['latest_zeitstempel'] ?? null),
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
