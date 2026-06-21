<?php
declare(strict_types=1);
// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

if (!STOKEN) die('SEC');

/**
 * POST /iot/register
 *
 * Registriert ein IoT-Gerät (ESP oder Pi). Provisioning-Modell:
 * Fleet-Token + Admin-Claim (siehe docs/design/iot-device-auth.md).
 *
 *  - Erst-Registrierung (neue chip_id): erfordert einen gültigen
 *    `X-Provisioning-Token` (Fleet-Token). Das Gerät wird als `pending`
 *    angelegt; WLAN-Credentials werden NICHT zurückgegeben (L2). Erst nach
 *    Admin-Freigabe liefert der Heartbeat die Netzwerk-Konfiguration.
 *  - Re-Registrierung (bekannte chip_id): wird NICHT mehr anonym akzeptiert
 *    (L1). Erlaubt nur als Selbst-Rotation mit dem gültigen bestehenden
 *    `X-Api-Key` des Geräts — sonst 409. Admin-Rotation läuft über
 *    POST /iot/devices/{id}/rotate-key.
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

            (new RateLimiter($this->pdo))->requireLimit('iot/register', 5, 60);

            $validated = $this->validateInput();
            if ($validated === null) {
                return;
            }

            $existing = $this->findExistingDevice($validated['chipId']);

            if ($existing !== null) {
                $this->handleReRegistration($existing, $validated);
            } else {
                $this->handleNewRegistration($validated);
            }

        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->handleError('Error registering IoT device', $e);
        }
    }

    /**
     * Erst-Registrierung: nur mit gültigem Fleet-Provisioning-Token.
     * Legt das Gerät als `pending` an. Keine WLAN-Credentials in der Response.
     */
    private function handleNewRegistration(array $validated): void {
        if (ProvisioningAuth::validateFleetToken($this->pdo) === null) {
            http_response_code(401);
            echo json_encode(['error' => 'Missing or invalid X-Provisioning-Token']);
            return;
        }

        $networkId = $this->getActiveNetworkId();
        if ($networkId === null) {
            return;
        }

        $apiKey = bin2hex(random_bytes(32));
        $apiKeyHash = hash('sha256', $apiKey);

        $this->pdo->beginTransaction();
        $deviceId = $this->insertDevice($validated, $networkId, $apiKey, $apiKeyHash);
        $this->insertSensoren($deviceId, $validated['sensoren']);
        $this->insertAktoren($deviceId, $validated['aktoren']);
        $this->pdo->commit();

        http_response_code(201);
        echo json_encode([
            'device_id' => $deviceId,
            'api_key'   => $apiKey,
            'status'    => 'pending',
            'message'   => 'Device registered. Awaiting admin approval before it can sync data or receive network credentials.',
        ]);
    }

    /**
     * Re-Registrierung bekannter chip_id. Nur als Selbst-Rotation mit dem
     * gültigen bestehenden X-Api-Key des Geräts; sonst 409 (kein anonymes
     * Überschreiben des Keys → schließt L1). Der Approval-Status bleibt
     * unverändert; Netzwerk-Credentials nur, wenn das Gerät bereits
     * `approved` ist.
     */
    private function handleReRegistration(array $existing, array $validated): void {
        if (!$this->verifyDeviceApiKey($existing)) {
            http_response_code(409);
            echo json_encode([
                'error' => 'Device already registered. Re-registration requires the device\'s valid X-Api-Key; otherwise rotate the key via admin.',
            ]);
            return;
        }

        $networkId = $this->getActiveNetworkId();
        if ($networkId === null) {
            return;
        }

        $deviceId = (int)$existing['iot_devices_id'];
        $apiKey = bin2hex(random_bytes(32));
        $apiKeyHash = hash('sha256', $apiKey);

        $this->pdo->beginTransaction();
        $this->updateDevice($deviceId, $validated, $networkId, $apiKey, $apiKeyHash);
        $this->pdo->prepare('DELETE FROM mbc_iot_sensoren WHERE mbc_iot_devices = ?')->execute([$deviceId]);
        $this->pdo->prepare('DELETE FROM mbc_iot_aktoren WHERE mbc_iot_devices = ?')->execute([$deviceId]);
        $this->insertSensoren($deviceId, $validated['sensoren']);
        $this->insertAktoren($deviceId, $validated['aktoren']);
        $this->pdo->commit();

        $isApproved = ($existing['provisioning_status'] ?? 'pending') === 'approved';

        $response = [
            'device_id' => $deviceId,
            'api_key'   => $apiKey,
            'status'    => $existing['provisioning_status'] ?? 'pending',
        ];
        if ($isApproved) {
            $response['network'] = $this->fetchNetworkConfig($networkId);
        } else {
            $response['message'] = 'Key rotated. Device still awaiting admin approval.';
        }

        http_response_code(200);
        echo json_encode($response);
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
     * Looks up an existing device by chip_id. Returns the row
     * (iot_devices_id, provisioning_status, api_key_hash) or null.
     */
    private function findExistingDevice(string $chipId): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT iot_devices_id, provisioning_status, api_key_hash
             FROM mbc_iot_devices WHERE chip_id = ?'
        );
        $stmt->execute([$chipId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Verifies that the supplied X-Api-Key matches the existing device's
     * stored hash (constant-time compare). Used to authorize self-rotation.
     */
    private function verifyDeviceApiKey(array $existing): bool {
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if ($apiKey === '' || empty($existing['api_key_hash'])) {
            return false;
        }
        return hash_equals((string)$existing['api_key_hash'], hash('sha256', $apiKey));
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
     * Insert a brand-new device as `pending` (awaiting admin approval).
     * Returns the new device ID.
     */
    private function insertDevice(array $validated, int $networkId, string $apiKey, string $apiKeyHash): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mbc_iot_devices
                (chip_id, name, typ, firmware_version, mbc_iot_networks, api_key, api_key_hash, online_status, provisioning_status, last_heartbeat)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $validated['chipId'], $validated['name'], $validated['typ'],
            $validated['firmwareVersion'], $networkId, $apiKey, $apiKeyHash,
            'unbekannt', 'pending'
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Update an existing device on self-rotation. Provisioning status is left
     * untouched (only an admin changes approval).
     */
    private function updateDevice(int $deviceId, array $validated, int $networkId, string $apiKey, string $apiKeyHash): void {
        $stmt = $this->pdo->prepare(
            'UPDATE mbc_iot_devices SET name = ?, typ = ?, firmware_version = ?,
             mbc_iot_networks = ?, api_key = ?, api_key_hash = ?, api_key_rotated_at = NOW(), updated_at = NOW()
             WHERE iot_devices_id = ?'
        );
        $stmt->execute([
            $validated['name'], $validated['typ'], $validated['firmwareVersion'],
            $networkId, $apiKey, $apiKeyHash, $deviceId
        ]);
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

    /**
     * Loads the WLAN/MQTT network config for the register response. Returned
     * only after successful provisioning auth (closes L2).
     */
    private function fetchNetworkConfig(int $networkId): array {
        $stmt = $this->pdo->prepare(
            'SELECT iot_ssid, iot_password, pi_local_ip, mqtt_port
             FROM mbc_iot_networks WHERE iot_networks_id = ?'
        );
        $stmt->execute([$networkId]);
        $networkConfig = $stmt->fetch();

        return [
            'iot_ssid'     => $networkConfig['iot_ssid'],
            'iot_password' => $networkConfig['iot_password'],
            'pi_local_ip'  => $networkConfig['pi_local_ip'],
            'mqtt_port'    => (int)$networkConfig['mqtt_port'],
        ];
    }
}
