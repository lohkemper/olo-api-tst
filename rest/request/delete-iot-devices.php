<?php
declare(strict_types=1);
// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

if (!STOKEN) die('SEC');

/**
 * DELETE /iot/devices/{id}
 *
 * Loescht ein Geraet samt Sensoren, Aktoren und kumulierten Sensordaten.
 * Der Cascade laeuft ueber ON DELETE CASCADE auf den Foreign-Keys nach
 * mbc_iot_devices (sql/12_iot_structure.sql) — die Messreihen des Geraets
 * sind danach unwiderruflich weg. Das Frontend warnt entsprechend im
 * Confirm-Dialog (STORY-2.7).
 *
 * Antworten:
 *  - HTTP 204  bei Erfolg (kein Body)
 *  - HTTP 400  bei fehlender / ungueltiger ID
 *  - HTTP 401  ohne Session
 *  - HTTP 404  wenn ID nicht existiert
 *
 * Erfordert Session-Authentifizierung (Admin), wie die uebrigen
 * schreibenden IoT-Admin-Endpunkte.
 *
 * Portiert aus dem nie gemergten Commit 93f108d (STORY-2.7). Der Handler
 * lag drei Monate ausschliesslich auf einem verwaisten Branch, waehrend das
 * Frontend ihn bereits aufrief — siehe STORY-1.11 / Phase 1.
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

            $stmt = $this->pdo->prepare(
                'SELECT iot_devices_id FROM mbc_iot_devices WHERE iot_devices_id = ?'
            );
            $stmt->execute([$deviceId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Device not found']);
                return;
            }

            $stmt = $this->pdo->prepare('DELETE FROM mbc_iot_devices WHERE iot_devices_id = ?');
            $stmt->execute([$deviceId]);

            http_response_code(204);

        } catch (\Throwable $e) {
            $this->handleError('Error deleting IoT device', $e);
        }
    }
}
