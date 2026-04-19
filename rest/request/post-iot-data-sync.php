<?php
declare(strict_types=1);
// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

if (!STOKEN) die('SEC');

/**
 * POST /iot/data-sync
 *
 * Empfängt kumulierte Sensordaten vom Raspberry Pi.
 * Authentifizierung über API-Key im Header (X-Api-Key).
 *
 * Body-Format:
 * {
 *   "data": [
 *     { "sensor_key": "temp1", "wert": 22.5, "min_wert": 20.1, "max_wert": 24.3, "avg_wert": 22.1, "anzahl_messungen": 60, "zeitstempel": "2026-04-08T14:00:00Z" },
 *     ...
 *   ]
 * }
 */
class requestPostIotDataSync extends RequestBase {
    private array $data = [];

    public function __construct(PDO $pdo, string $area) {
        parent::__construct($pdo, $area);
    }

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            $device = ApiKeyAuth::authenticateDevice($this->pdo);
            if ($device === null) {
                return;
            }

            $deviceId = (int)$device['iot_devices_id'];

            (new RateLimiter($this->pdo))->requireLimit(
                'iot/data-sync:' . $deviceId, 60, 60
            );
            $dataPoints = $this->data['data'] ?? [];

            if (empty($dataPoints) || !is_array($dataPoints)) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing or empty data array']);
                return;
            }

            // Update heartbeat
            $this->pdo->prepare(
                'UPDATE mbc_iot_devices SET last_heartbeat = NOW(), online_status = ? WHERE iot_devices_id = ?'
            )->execute(['online', $deviceId]);

            // Insert sensor data
            $stmt = $this->pdo->prepare(
                'INSERT INTO mbc_iot_sensor_data (mbc_iot_devices, sensor_key, wert, min_wert, max_wert, avg_wert, anzahl_messungen, zeitstempel)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $inserted = 0;
            $this->pdo->beginTransaction();

            foreach ($dataPoints as $point) {
                if (empty($point['sensor_key']) || !isset($point['wert'])) {
                    continue;
                }

                $stmt->execute([
                    $deviceId,
                    trim($point['sensor_key']),
                    (float)$point['wert'],
                    isset($point['min_wert']) ? (float)$point['min_wert'] : null,
                    isset($point['max_wert']) ? (float)$point['max_wert'] : null,
                    isset($point['avg_wert']) ? (float)$point['avg_wert'] : null,
                    (int)($point['anzahl_messungen'] ?? 1),
                    $point['zeitstempel'] ?? date('c')
                ]);
                $inserted++;
            }

            $this->pdo->commit();

            echo json_encode([
                'status' => 'ok',
                'inserted' => $inserted,
                'timestamp' => date('c')
            ]);

        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->handleError('Error syncing sensor data', $e);
        }
    }
}
