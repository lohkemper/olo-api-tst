<?php
declare(strict_types=1);
// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

if (!STOKEN) die('SEC');

/**
 * POST /iot/pi-sync
 *
 * Empfängt kumulierte Sensordaten vom Raspberry Pi für ALLE Devices
 * in seinem Netzwerk. Nur Devices vom Typ "pi" dürfen diesen Endpoint
 * nutzen — ESPs nutzen weiterhin POST /iot/data-sync mit ihrem eigenen Key.
 *
 * Auth: X-Api-Key Header (muss einem Device mit typ="pi" gehören)
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
            header('Content-Type: application/json; charset=utf-8');

            // Authenticate via API key
            $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
            if (empty($apiKey)) {
                http_response_code(401);
                echo json_encode(['error' => 'Missing X-Api-Key header']);
                return;
            }

            // Find device by API key — must be typ=pi
            $stmt = $this->pdo->prepare(
                'SELECT iot_devices_id, typ FROM mbc_iot_devices WHERE api_key = ?'
            );
            $stmt->execute([$apiKey]);
            $piDevice = $stmt->fetch();

            if (!$piDevice) {
                http_response_code(401);
                echo json_encode(['error' => 'Invalid API key']);
                return;
            }

            if ($piDevice['typ'] !== 'pi') {
                http_response_code(403);
                echo json_encode(['error' => 'Only devices with typ=pi may use pi-sync']);
                return;
            }

            // Update Pi heartbeat
            $this->pdo->prepare(
                'UPDATE mbc_iot_devices SET last_heartbeat = NOW(), online_status = ? WHERE iot_devices_id = ?'
            )->execute(['online', $piDevice['iot_devices_id']]);

            $devices = $this->data['devices'] ?? [];
            if (empty($devices) || !is_array($devices)) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing or empty devices array']);
                return;
            }

            // Prepare statements
            $findDevice = $this->pdo->prepare(
                'SELECT iot_devices_id FROM mbc_iot_devices WHERE chip_id = ?'
            );
            $insertData = $this->pdo->prepare(
                'INSERT INTO mbc_iot_sensor_data (mbc_iot_devices, sensor_key, wert, min_wert, max_wert, avg_wert, anzahl_messungen, zeitstempel)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $updateHeartbeat = $this->pdo->prepare(
                'UPDATE mbc_iot_devices SET last_heartbeat = NOW(), online_status = ? WHERE iot_devices_id = ?'
            );

            $this->pdo->beginTransaction();
            $totalInserted = 0;
            $devicesProcessed = 0;
            $errors = [];

            foreach ($devices as $deviceEntry) {
                $chipId = trim($deviceEntry['chip_id'] ?? '');
                $dataPoints = $deviceEntry['data'] ?? [];

                if (empty($chipId)) {
                    $errors[] = 'Skipped entry with empty chip_id';
                    continue;
                }

                // Resolve chip_id to device_id
                $findDevice->execute([$chipId]);
                $device = $findDevice->fetch();
                if (!$device) {
                    $errors[] = "Unknown chip_id: {$chipId}";
                    continue;
                }

                $deviceId = (int)$device['iot_devices_id'];

                // Update device heartbeat (the Pi reports on behalf of the ESP)
                $updateHeartbeat->execute(['online', $deviceId]);

                if (empty($dataPoints) || !is_array($dataPoints)) {
                    $devicesProcessed++;
                    continue;
                }

                foreach ($dataPoints as $point) {
                    if (empty($point['sensor_key']) || !isset($point['wert'])) {
                        continue;
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
                    $totalInserted++;
                }
                $devicesProcessed++;
            }

            $this->pdo->commit();

            $response = [
                'status' => 'ok',
                'devices_processed' => $devicesProcessed,
                'data_points_inserted' => $totalInserted,
                'timestamp' => date('c')
            ];
            if (!empty($errors)) {
                $response['warnings'] = $errors;
            }

            echo json_encode($response);

        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->handleError('Error in pi-sync', $e);
        }
    }
}
