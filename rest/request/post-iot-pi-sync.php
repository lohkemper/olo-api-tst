<?php
declare(strict_types=1);
// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

if (!STOKEN) die('SEC');

/**
 * POST /iot/pi-sync
 *
 * Empfaengt kumulierte Sensordaten vom Raspberry Pi fuer ALLE Devices
 * in seinem Netzwerk. Nur Devices vom Typ "pi" duerfen diesen Endpoint
 * nutzen — ESPs nutzen weiterhin POST /iot/data-sync mit ihrem eigenen Key.
 *
 * Auth: X-Api-Key Header (muss einem Device mit typ="pi" gehoeren)
 *
 * Body:
 * {
 *   "devices": [
 *     {
 *       "chip_id": "3cf8f4",
 *       "data": [
 *         {
 *           "sensor_key": "boden",
 *           "wert": 99.5,
 *           "min_wert": 98.0,
 *           "max_wert": 100.0,
 *           "avg_wert": 99.2,
 *           "anzahl_messungen": 30,
 *           "zeitstempel": "2026-04-12T10:00:00Z"
 *         }
 *       ]
 *     }
 *   ]
 * }
 */
class requestPostIotPiSync extends RequestBase {
    private array $data = [];

    public function __construct(PDO $pdo, string $area) {
        parent::__construct($pdo, $area);
    }

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            $piDevice = ApiKeyAuth::authenticateDevice($this->pdo, ['pi']);
            if ($piDevice === null) {
                return;
            }

            (new RateLimiter($this->pdo))->requireLimit(
                'iot/pi-sync:' . (int)$piDevice['iot_devices_id'], 20, 60
            );

            $devices = $this->data['devices'] ?? [];
            if (empty($devices) || !is_array($devices)) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing or empty devices array']);
                return;
            }

            $this->touchHeartbeat((int)$piDevice['iot_devices_id']);

            $this->pdo->beginTransaction();
            $result = $this->processDevices($devices);
            if ($result === null) {
                // Validation error inside processDevices — response already sent, rollback done
                return;
            }
            $this->pdo->commit();

            $response = [
                'status' => 'ok',
                'devices_processed' => $result['devicesProcessed'],
                'data_points_inserted' => $result['totalInserted'],
                'timestamp' => date('c')
            ];
            if (!empty($result['warnings'])) {
                $response['warnings'] = $result['warnings'];
            }
            echo json_encode($response);

        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->handleError('Error in pi-sync', $e);
        }
    }

    private function touchHeartbeat(int $deviceId): void {
        $this->pdo->prepare(
            'UPDATE mbc_iot_devices SET last_heartbeat = NOW(), online_status = ? WHERE iot_devices_id = ?'
        )->execute(['online', $deviceId]);
    }

    /**
     * Iterate devices and data points. Returns stats array on success,
     * or null if a validation error occurred (HTTP response already sent).
     */
    private function processDevices(array $devices): ?array {
        $findDevice = $this->pdo->prepare(
            'SELECT iot_devices_id FROM mbc_iot_devices WHERE chip_id = ?'
        );
        $insertData = $this->pdo->prepare(
            'INSERT INTO mbc_iot_sensor_data (mbc_iot_devices, sensor_key, wert, min_wert, max_wert, avg_wert, anzahl_messungen, zeitstempel)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                wert = VALUES(wert),
                min_wert = VALUES(min_wert),
                max_wert = VALUES(max_wert),
                avg_wert = VALUES(avg_wert),
                anzahl_messungen = VALUES(anzahl_messungen)'
        );
        $updateHeartbeat = $this->pdo->prepare(
            'UPDATE mbc_iot_devices SET last_heartbeat = NOW(), online_status = ? WHERE iot_devices_id = ?'
        );

        $totalInserted = 0;
        $devicesProcessed = 0;
        $warnings = [];

        foreach ($devices as $deviceEntry) {
            $chipId = trim($deviceEntry['chip_id'] ?? '');
            $dataPoints = $deviceEntry['data'] ?? [];

            if (empty($chipId)) {
                $warnings[] = 'Skipped entry with empty chip_id';
                continue;
            }

            $findDevice->execute([$chipId]);
            $device = $findDevice->fetch();
            if (!$device) {
                $warnings[] = "Unknown chip_id: {$chipId}";
                continue;
            }

            $deviceId = (int)$device['iot_devices_id'];
            $updateHeartbeat->execute(['online', $deviceId]);

            if (empty($dataPoints) || !is_array($dataPoints)) {
                $devicesProcessed++;
                continue;
            }

            $inserted = $this->insertDataPoints($insertData, $deviceId, $chipId, $dataPoints);
            if ($inserted === null) {
                return null;
            }
            $totalInserted += $inserted;
            $devicesProcessed++;
        }

        return [
            'totalInserted' => $totalInserted,
            'devicesProcessed' => $devicesProcessed,
            'warnings' => $warnings,
        ];
    }

    /**
     * Insert all data points for a device. Returns count or null on validation error.
     */
    private function insertDataPoints(PDOStatement $insertData, int $deviceId, string $chipId, array $dataPoints): ?int {
        $count = 0;
        foreach ($dataPoints as $idx => $point) {
            $missing = [];
            if (empty($point['sensor_key'])) {
                $missing[] = 'sensor_key';
            }
            if (!isset($point['wert'])) {
                $missing[] = 'wert';
            }
            if (!empty($missing)) {
                $this->pdo->rollBack();
                http_response_code(400);
                echo json_encode([
                    'error' => "Device '{$chipId}' data[{$idx}] missing required fields: " . implode(', ', $missing)
                ]);
                return null;
            }

            $insertData->execute([
                $deviceId,
                trim($point['sensor_key']),
                (float)$point['wert'],
                isset($point['min_wert']) ? (float)$point['min_wert'] : null,
                isset($point['max_wert']) ? (float)$point['max_wert'] : null,
                isset($point['avg_wert']) ? (float)$point['avg_wert'] : null,
                (int)($point['anzahl_messungen'] ?? 1),
                $point['zeitstempel'] ?? date('c')
            ]);
            $count++;
        }
        return $count;
    }
}
