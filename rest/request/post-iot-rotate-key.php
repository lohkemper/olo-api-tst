<?php
declare(strict_types=1);
// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

if (!STOKEN) die('SEC');

/**
 * POST /iot/devices/{id}/rotate-key
 *
 * Rotiert den API-Key eines Geraets. Admin-only (Session-Auth via
 * RequestBase::requireAuth). Generiert einen neuen 32-Byte-Key,
 * speichert nur den SHA-256-Hash in der DB, setzt api_key_rotated_at
 * und liefert den Klartext-Key genau einmal in der Response zurueck.
 * Der Klartext wird NICHT gespeichert — nach dem Response existiert
 * er nirgends mehr auf dem Server.
 */
class requestPostIotRotateKey extends RequestBase {
    private int $deviceId = 0;

    public function __construct(PDO $pdo, string $area) {
        parent::__construct($pdo, $area);
    }

    public function setDeviceId(int $id): void {
        $this->deviceId = $id;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $this->requireAuth();

            if ($this->deviceId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing device id']);
                return;
            }

            $stmt = $this->pdo->prepare(
                'SELECT iot_devices_id, chip_id, name, typ FROM mbc_iot_devices WHERE iot_devices_id = ?'
            );
            $stmt->execute([$this->deviceId]);
            $device = $stmt->fetch();

            if (!$device) {
                http_response_code(404);
                echo json_encode(['error' => 'Device not found']);
                return;
            }

            // ESP-Geraete koennen einen rotierten Key nicht entgegennehmen:
            // ihre Firmware hat keinen Kanal dafuer, und der Selbstheilungs-
            // pfad (Heartbeat 401 -> Re-Register) endet bei bekannter chip_id
            // im 409. Eine Rotation wuerde das Geraet bis zum Neuflashen
            // abmelden. Die Pi-Zentrale hat mit `pks-backend -set-api-key`
            // einen Weg, ESPs nicht. Siehe STORY-4.4.
            if (in_array($device['typ'], ['esp32', 'esp8266'], true)) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Key rotation is not supported for ESP devices',
                    'reason' => 'The device has no way to receive the new key and would stay offline until reflashed.',
                    'remedy' => 'Delete the device and let it register again with the fleet token.',
                    'typ' => $device['typ'],
                ]);
                return;
            }

            $newKey = bin2hex(random_bytes(32));
            $newHash = hash('sha256', $newKey);

            $update = $this->pdo->prepare(
                'UPDATE mbc_iot_devices
                 SET api_key = ?, api_key_hash = ?, api_key_rotated_at = NOW(), updated_at = NOW()
                 WHERE iot_devices_id = ?'
            );
            $update->execute([$newKey, $newHash, $this->deviceId]);

            http_response_code(200);
            echo json_encode([
                'status' => 'ok',
                'device_id' => (int)$device['iot_devices_id'],
                'chip_id' => $device['chip_id'],
                'api_key' => $newKey,
                'rotated_at' => gmdate('c')
            ]);

        } catch (\Throwable $e) {
            $this->handleError('Error rotating device api key', $e);
        }
    }
}
