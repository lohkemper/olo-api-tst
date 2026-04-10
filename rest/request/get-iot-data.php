<?php
declare(strict_types=1);
// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

if (!STOKEN) die('SEC');

/**
 * GET /iot/data/{device_id}                    — Sensordaten eines Geräts (letzte 24h)
 * GET /iot/data/{device_id}?from=...&to=...    — Sensordaten in Zeitraum
 * GET /iot/data/{device_id}?sensor_key=temp1   — Nur bestimmter Sensor
 */
class requestGetIotData extends RequestBase {
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

            if (!$deviceId) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing device_id']);
                return;
            }

            // Verify device exists
            $stmt = $this->pdo->prepare('SELECT iot_devices_id, name FROM mbc_iot_devices WHERE iot_devices_id = ?');
            $stmt->execute([(int)$deviceId]);
            $device = $stmt->fetch();

            if (!$device) {
                http_response_code(404);
                echo json_encode(['error' => 'Device not found']);
                return;
            }

            // Build query
            $params = [(int)$deviceId];
            $where = ['sd.mbc_iot_devices = ?'];

            // Filter by sensor_key
            $sensorKey = $this->request['sensor_key'] ?? null;
            if ($sensorKey) {
                $where[] = 'sd.sensor_key = ?';
                $params[] = $sensorKey;
            }

            // Filter by time range
            $from = $this->request['from'] ?? date('Y-m-d H:i:s', strtotime('-24 hours'));
            $to = $this->request['to'] ?? date('Y-m-d H:i:s');

            $where[] = 'sd.zeitstempel >= ?';
            $params[] = $from;
            $where[] = 'sd.zeitstempel <= ?';
            $params[] = $to;

            $whereClause = implode(' AND ', $where);

            $stmt = $this->pdo->prepare(
                "SELECT sd.iot_sensor_data_id, sd.sensor_key, sd.wert, sd.min_wert, sd.max_wert,
                        sd.avg_wert, sd.anzahl_messungen, sd.zeitstempel
                 FROM mbc_iot_sensor_data sd
                 WHERE {$whereClause}
                 ORDER BY sd.zeitstempel DESC
                 LIMIT 1000"
            );
            $stmt->execute($params);
            $data = $stmt->fetchAll();

            echo json_encode([
                'device_id' => (int)$deviceId,
                'device_name' => $device['name'],
                'from' => $from,
                'to' => $to,
                'count' => count($data),
                'data' => $data
            ]);

        } catch (\Throwable $e) {
            $this->handleError('Error fetching sensor data', $e);
        }
    }
}
