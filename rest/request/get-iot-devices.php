<?php
declare(strict_types=1);
// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

if (!STOKEN) die('SEC');

/**
 * GET /iot/devices          — Alle registrierten Geräte
 * GET /iot/devices/{id}     — Einzelnes Gerät mit Sensoren und Aktoren
 *
 * Jede Antwort enthält ein abgeleitetes Feld `device_key` — die mittleren zwei
 * Pfadteile aus dem MQTT-Topic-Schema `pks/{projekt}/{bereich}/{messwert}`.
 * Der Pi-Sync nutzt das Feld, um seine lokale `chip_id_map` (device_key → chip_id)
 * zu pflegen, ohne pro Geraet manuelles SQL zu brauchen — siehe STORY-1.8.
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
                    CASE
                      WHEN d.last_heartbeat IS NULL THEN \'unbekannt\'
                      WHEN d.last_heartbeat > NOW() - INTERVAL 15 MINUTE THEN \'online\'
                      ELSE \'offline\'
                    END AS online_status,
                    d.last_heartbeat, d.registered_at,
                    n.name AS network_name, n.pi_local_ip
             FROM mbc_iot_devices d
             LEFT JOIN mbc_iot_networks n ON d.mbc_iot_networks = n.iot_networks_id
             ORDER BY d.name'
        );
        $stmt->execute();
        $devices = $stmt->fetchAll();

        // Add sensor/actor counts and derive device_key from a sample MQTT topic
        foreach ($devices as &$device) {
            $id = $device['iot_devices_id'];

            $stmt = $this->pdo->prepare('SELECT COUNT(*) AS cnt FROM mbc_iot_sensoren WHERE mbc_iot_devices = ?');
            $stmt->execute([$id]);
            $device['sensor_count'] = (int)$stmt->fetch()['cnt'];

            $stmt = $this->pdo->prepare('SELECT COUNT(*) AS cnt FROM mbc_iot_aktoren WHERE mbc_iot_devices = ?');
            $stmt->execute([$id]);
            $device['aktor_count'] = (int)$stmt->fetch()['cnt'];

            $device['device_key'] = $this->deriveDeviceKey($id);
        }

        echo json_encode($devices);
    }

    /**
     * Leitet den device_key (z.B. "garten/klima") aus dem MQTT-Topic-Schema
     * `pks/{projekt}/{bereich}/{messwert}` ab. Bevorzugt einen Sensor-Topic,
     * fällt auf einen Aktor-Set-Topic zurück. Liefert null, wenn das Geraet
     * weder Sensoren noch Aktoren hat (z.B. Pi-Devices).
     */
    private function deriveDeviceKey(int $deviceId): ?string {
        $stmt = $this->pdo->prepare(
            'SELECT mqtt_topic FROM mbc_iot_sensoren WHERE mbc_iot_devices = ?
             ORDER BY iot_sensoren_id LIMIT 1'
        );
        $stmt->execute([$deviceId]);
        $row = $stmt->fetch();
        $topic = $row['mqtt_topic'] ?? null;

        if (!$topic) {
            $stmt = $this->pdo->prepare(
                'SELECT mqtt_topic_set FROM mbc_iot_aktoren WHERE mbc_iot_devices = ?
                 ORDER BY iot_aktoren_id LIMIT 1'
            );
            $stmt->execute([$deviceId]);
            $row = $stmt->fetch();
            $topic = $row['mqtt_topic_set'] ?? null;
        }

        if (!$topic) {
            return null;
        }

        $parts = explode('/', trim($topic, '/'));
        // Expect: pks/{projekt}/{bereich}/{messwert} → at least 4 parts
        if (count($parts) < 3 || $parts[0] !== 'pks') {
            return null;
        }
        return $parts[1] . '/' . $parts[2];
    }

    private function getDeviceById(int $deviceId): void {
        // Fetch device
        $stmt = $this->pdo->prepare(
            'SELECT d.iot_devices_id, d.chip_id, d.name, d.typ, d.firmware_version,
                    CASE
                      WHEN d.last_heartbeat IS NULL THEN \'unbekannt\'
                      WHEN d.last_heartbeat > NOW() - INTERVAL 15 MINUTE THEN \'online\'
                      ELSE \'offline\'
                    END AS online_status,
                    d.last_heartbeat, d.registered_at,
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
        $device['device_key'] = $this->deriveDeviceKey($deviceId);

        echo json_encode($device);
    }
}
