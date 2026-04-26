<?php
declare(strict_types=1);
// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

if (!STOKEN) die('SEC');

/**
 * DELETE /iot/devices/{id}
 *
 * Loescht ein Geraet samt Sensoren, Aktoren und kumulierten Sensordaten
 * (Cascade ueber Foreign-Keys auf mbc_iot_devices).
 *
 * Antworten:
 *  - HTTP 204  bei Erfolg (kein Body)
 *  - HTTP 400  bei fehlender / ungueltiger ID
 *  - HTTP 401  ohne Session
 *  - HTTP 404  wenn ID nicht existiert
 *
 * Erfordert Session-Authentifizierung (Admin).
 */
class requestDeleteIotDevices extends RequestBase {
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
            $this->requireAuth();

            $deviceId = (int)($this->request['id'] ?? 0);
            if ($deviceId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing or invalid device id']);
                return;
            }

            // Verify device exists (and capture chip_id for the response payload)
            $stmt = $this->pdo->prepare(
                'SELECT iot_devices_id, chip_id, name FROM mbc_iot_devices WHERE iot_devices_id = ?'
            );
            $stmt->execute([$deviceId]);
            $device = $stmt->fetch();
            if (!$device) {
                http_response_code(404);
                echo json_encode(['error' => 'Device not found']);
                return;
            }

            // Cascade on mbc_iot_sensoren / mbc_iot_aktoren / mbc_iot_sensor_data
            // is enforced by ON DELETE CASCADE on the foreign keys.
            $stmt = $this->pdo->prepare('DELETE FROM mbc_iot_devices WHERE iot_devices_id = ?');
            $stmt->execute([$deviceId]);

            http_response_code(204);

        } catch (\Throwable $e) {
            $this->handleError('Error deleting IoT device', $e);
        }
    }
}
