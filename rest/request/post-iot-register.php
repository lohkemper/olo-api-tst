<?php
declare(strict_types=1);
// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

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

            $validated = $this->validateInput();
            if ($validated === null) {
                return;
            }

            $networkId = $this->getActiveNetworkId();
            if ($networkId === null) {
                return;
            }

            $apiKey = bin2hex(random_bytes(32));
            $apiKeyHash = hash('sha256', $apiKey);

            $this->pdo->beginTransaction();

            $deviceId = $this->upsertDevice($validated, $networkId, $apiKey, $apiKeyHash);
            $this->insertSensoren($deviceId, $validated['sensoren']);
            $this->insertAktoren($deviceId, $validated['aktoren']);

            $this->pdo->commit();

            $this->sendSuccessResponse($deviceId, $apiKey, $networkId, $validated['isReRegistration']);

        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->handleError('Error registering IoT device', $e);
        }
    }

    /**
     * Validate all input fields including sensoren/aktoren.
     * Returns validated data array or null (with HTTP 400 already sent).
     */
    private function validateInput(): ?array {
        $requiredFields = ['chip_id', 'name', 'typ'];
        foreach ($requiredFields as $field) {
            if (empty($this->data[$field])) {
                http_response_code(400);
                echo json_encode(['error' => "Missing required field: {$field}"]);
                return null;
            }
        }

        $typ = trim($this->data['typ']);
        if (!in_array($typ, ['pi', 'esp32', 'esp8266'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid device type. Allowed: pi, esp32, esp8266']);
            return null;
        }

        $sensoren = $this->data['sensoren'] ?? [];
        $aktoren = $this->data['aktoren'] ?? [];

        if (!empty($sensoren) && is_array($sensoren)) {
            $error = $this->validateEntries($sensoren, 'Sensor', ['id', 'typ', 'einheit']);
            if ($error !== null) {
                http_response_code(400);
                echo json_encode(['error' => $error]);
                return null;
            }
        }

        if (!empty($aktoren) && is_array($aktoren)) {
            $error = $this->validateEntries($aktoren, 'Aktor', ['id', 'typ', 'name']);
            if ($error !== null) {
                http_response_code(400);
                echo json_encode(['error' => $error]);
                return null;
            }
        }

        return [
            'chipId' => trim($this->data['chip_id']),
            'name' => trim($this->data['name']),
            'typ' => $typ,
            'firmwareVersion' => trim($this->data['firmware_version'] ?? ''),
            'sensoren' => is_array($sensoren) ? $sensoren : [],
            'aktoren' => is_array($aktoren) ? $aktoren : [],
            'isReRegistration' => false,
        ];
    }

    /**
     * Validate required fields on each entry in a list.
     * Returns error string or null if valid.
     */
    private function validateEntries(array $entries, string $label, array $requiredKeys): ?string {
        foreach ($entries as $idx => $entry) {
            $missing = [];
            foreach ($requiredKeys as $key) {
                if (empty($entry[$key])) {
                    $missing[] = $key;
                }
            }
            if (!empty($missing)) {
                return "{$label} [{$idx}] missing required fields: " . implode(', ', $missing);
            }
        }
        return null;
    }

    /**
     * Always use the server-side active network (client network_id is ignored).
     * Returns network ID or null (with HTTP 500 already sent).
     */
    private function getActiveNetworkId(): ?int {
        $stmt = $this->pdo->prepare(
            'SELECT iot_networks_id FROM mbc_iot_networks WHERE is_active = 1 LIMIT 1'
        );
        $stmt->execute();
        $network = $stmt->fetch();
        if (!$network) {
            http_response_code(500);
            echo json_encode(['error' => 'No active network configured']);
            return null;
        }
        return (int)$network['iot_networks_id'];
    }

    /**
     * Insert or update device. Returns the device ID.
     */
    private function upsertDevice(array &$validated, int $networkId, string $apiKey, string $apiKeyHash): int {
        $stmt = $this->pdo->prepare(
            'SELECT iot_devices_id FROM mbc_iot_devices WHERE chip_id = ?'
        );
        $stmt->execute([$validated['chipId']]);
        $existing = $stmt->fetch();

        if ($existing) {
            $deviceId = (int)$existing['iot_devices_id'];
            $stmt = $this->pdo->prepare(
                'UPDATE mbc_iot_devices SET name = ?, typ = ?, firmware_version = ?,
                 mbc_iot_networks = ?, api_key = ?, api_key_hash = ?, online_status = ?, updated_at = NOW()
                 WHERE iot_devices_id = ?'
            );
            $stmt->execute([
                $validated['name'], $validated['typ'], $validated['firmwareVersion'],
                $networkId, $apiKey, $apiKeyHash, 'online', $deviceId
            ]);

            $this->pdo->prepare('DELETE FROM mbc_iot_sensoren WHERE mbc_iot_devices = ?')->execute([$deviceId]);
            $this->pdo->prepare('DELETE FROM mbc_iot_aktoren WHERE mbc_iot_devices = ?')->execute([$deviceId]);
            $validated['isReRegistration'] = true;
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO mbc_iot_devices (chip_id, name, typ, firmware_version, mbc_iot_networks, api_key, api_key_hash, online_status, last_heartbeat)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                $validated['chipId'], $validated['name'], $validated['typ'],
                $validated['firmwareVersion'], $networkId, $apiKey, $apiKeyHash, 'online'
            ]);
            $deviceId = (int)$this->pdo->lastInsertId();
        }

        return $deviceId;
    }

    private function insertSensoren(int $deviceId, array $sensoren): void {
        if (empty($sensoren)) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO mbc_iot_sensoren (mbc_iot_devices, sensor_key, typ, einheit, modell, intervall_sekunden, mqtt_topic)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($sensoren as $sensor) {
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

    private function insertAktoren(int $deviceId, array $aktoren): void {
        if (empty($aktoren)) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO mbc_iot_aktoren (mbc_iot_devices, aktor_key, typ, name, zustaende, mqtt_topic_set, mqtt_topic_status)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($aktoren as $aktor) {
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

    private function sendSuccessResponse(int $deviceId, string $apiKey, int $networkId, bool $isReRegistration): void {
        $stmt = $this->pdo->prepare(
            'SELECT iot_ssid, iot_password, inet_ssid, inet_password, pi_local_ip, mqtt_port
             FROM mbc_iot_networks WHERE iot_networks_id = ?'
        );
        $stmt->execute([$networkId]);
        $networkConfig = $stmt->fetch();

        http_response_code($isReRegistration ? 200 : 201);
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
    }
}
