<?php
declare(strict_types=1);
// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

if (!STOKEN) die('SEC');

/**
 * POST /iot/heartbeat
 *
 * Aktualisiert den Online-Status und Heartbeat eines Geräts.
 * Authentifizierung über API-Key im Header (X-Api-Key).
 */
class requestPostIotHeartbeat extends RequestBase {
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

            // Update heartbeat
            $stmt = $this->pdo->prepare(
                'UPDATE mbc_iot_devices SET last_heartbeat = NOW(), online_status = ? WHERE iot_devices_id = ?'
            );
            $stmt->execute(['online', $device['iot_devices_id']]);

            echo json_encode([
                'status' => 'ok',
                'device_id' => (int)$device['iot_devices_id'],
                'timestamp' => date('c')
            ]);

        } catch (\Throwable $e) {
            $this->handleError('Error processing heartbeat', $e);
        }
    }
}
