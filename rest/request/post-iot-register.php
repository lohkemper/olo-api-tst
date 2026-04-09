// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary
<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /iot/register
 *
 * Registriert ein neues IoT-Gerät (ESP oder Pi).
 * Speichert Sensoren und Aktoren aus der Selbstbeschreibung.
 * Antwortet mit Netzwerk-Konfiguration (IoT-WLAN, Pi-IP, MQTT).
 *
 * Keine Session-Auth nötig — Authentifizierung über API-Key im Header.
 */
class requestPostIotRegister extends RequestBase {
    private array $data = [];

    public function __construct(PDO $pdo, string $area) {
        parent::__construct($pdo, $area);
    }

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            // Validate required fields
            $requiredFields = ['chip_id', 'name', 'typ'];
            foreach ($requiredFields as $field) {
                if (empty($this->data[$field])) {
                    http_response_code(400);
                    echo json_encode(['error' => "Missing required field: {$field}"]);
                    return;
                }
            }

            $chipId = trim($this->data['chip_id']);
            $name = trim($this->data['name']);
            $typ = trim($this->data['typ']);
            $firmwareVersion = trim($this->data['firmware_version'] ?? '');
            $networkId = (int)($this->data['network_id'] ?? 0);
            $sensoren = $this->data['sensoren'] ?? [];
            $aktoren = $this->data['aktoren'] ?? [];

            // Validate device type
            if (!in_array($typ, ['pi', 'esp32', 'esp8266'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid device type. Allowed: pi, esp32, esp8266']);
                return;
            }

            // Get network (use first active network if not specified)
            if ($networkId === 0) {
                $stmt = $this->pdo->prepare(
                    'SELECT iot_networks_id FROM mbc_iot_networks WHERE is_active = 1 LIMIT 1'
                );
                $stmt->execute();
                $network = $stmt->fetch();
                if (!$network) {
                    http_response_code(500);
                    echo json_encode(['error' => 'No active network configured']);
                    return;
                }
                $networkId = (int)$network['iot_networks_id'];
            }

            // Generate API key for the device
            $apiKey = bin2hex(random_bytes(32));

            // Check if device already exists (re-registration)
            $stmt = $this->pdo->prepare(
                'SELECT iot_devices_id FROM mbc_iot_devices WHERE chip_id = ?'
            );
            $stmt->execute([$chipId]);
            $existing = $stmt->fetch();

            $this->pdo->beginTransaction();

            if ($existing) {
                // Update existing device
                $deviceId = (int)$existing['iot_devices_id'];
                $stmt = $this->pdo->prepare(
                    'UPDATE mbc_iot_devices SET name = ?, typ = ?, firmware_version = ?,
                     mbc_iot_networks = ?, api_key = ?, online_status = ?, updated_at = NOW()
                     WHERE iot_devices_id = ?'
                );
                $stmt->execute([$name, $typ, $firmwareVersion, $networkId, $apiKey, 'online', $deviceId]);

                // Remove old sensors and actors (re-registration replaces them)
                $this->pdo->prepare('DELETE FROM mbc_iot_sensoren WHERE mbc_iot_devices = ?')->execute([$deviceId]);
                $this->pdo->prepare('DELETE FROM mbc_iot_aktoren WHERE mbc_iot_devices = ?')->execute([$deviceId]);
            } else {
                // Insert new device
                $stmt = $this->pdo->prepare(
                    'INSERT INTO mbc_iot_devices (chip_id, name, typ, firmware_version, mbc_iot_networks, api_key, online_status, last_heartbeat)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
                );
                $stmt->execute([$chipId, $name, $typ, $firmwareVersion, $networkId, $apiKey, 'online']);
                $deviceId = (int)$this->pdo->lastInsertId();
            }

            // Insert sensors
            if (!empty($sensoren) && is_array($sensoren)) {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO mbc_iot_sensoren (mbc_iot_devices, sensor_key, typ, einheit, modell, intervall_sekunden, mqtt_topic)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                foreach ($sensoren as $sensor) {
                    if (empty($sensor['id']) || empty($sensor['typ']) || empty($sensor['einheit'])) {
                        continue;
                    }
                    $stmt->execute([
                        $deviceId,
                        trim($sensor['id']),
                        trim($sensor['typ']),
                        trim($sensor['einheit']),
                        trim($sensor['modell'] ?? ''),
                        (int)($sensor['intervall_sekunden'] ?? 30),
                        trim($sensor['mqtt_topic'] ?? '')
                    ]);
                }
            }

            // Insert actors
            if (!empty($aktoren) && is_array($aktoren)) {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO mbc_iot_aktoren (mbc_iot_devices, aktor_key, typ, name, zustaende, mqtt_topic_set, mqtt_topic_status)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                foreach ($aktoren as $aktor) {
                    if (empty($aktor['id']) || empty($aktor['typ']) || empty($aktor['name'])) {
                        continue;
                    }
                    $stmt->execute([
                        $deviceId,
                        trim($aktor['id']),
                        trim($aktor['typ']),
                        trim($aktor['name']),
                        json_encode($aktor['zustaende'] ?? []),
                        trim($aktor['mqtt_topic_set'] ?? ''),
                        trim($aktor['mqtt_topic_status'] ?? '')
                    ]);
                }
            }

            $this->pdo->commit();

            // Fetch network config for response
            $stmt = $this->pdo->prepare(
                'SELECT iot_ssid, iot_password, inet_ssid, inet_password, pi_local_ip, mqtt_port
                 FROM mbc_iot_networks WHERE iot_networks_id = ?'
            );
            $stmt->execute([$networkId]);
            $networkConfig = $stmt->fetch();

            // Response with device ID, API key and network config
            http_response_code($existing ? 200 : 201);
            echo json_encode([
                'device_id' => $deviceId,
                'api_key' => $apiKey,
                'network' => [
                    'iot_ssid' => $networkConfig['iot_ssid'],
                    'iot_password' => $networkConfig['iot_password'],
                    'pi_local_ip' => $networkConfig['pi_local_ip'],
                    'mqtt_port' => (int)$networkConfig['mqtt_port']
                ]
            ]);

        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->handleError('Error registering IoT device', $e);
        }
    }
}
