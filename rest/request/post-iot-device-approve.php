<?php
declare(strict_types=1);
// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

if (!STOKEN) die('SEC');

/**
 * POST /iot/devices/{id}/approve
 * POST /iot/devices/{id}/revoke
 *
 * Admin-Claim für IoT-Geräte (Session-Auth via RequestBase::requireAuth).
 *
 *  - approve: setzt `provisioning_status = 'approved'` + `approved_at`. Erst
 *    danach darf das Gerät Daten senden (data-sync/pi-sync) und erhält über
 *    den Heartbeat seine Netzwerk-Credentials.
 *  - revoke:  setzt `provisioning_status = 'revoked'`. Das Gerät wird ab sofort
 *    von allen Geräte-Endpoints mit 403 abgewiesen.
 */
class requestPostIotDeviceApprove extends RequestBase {
    private int $deviceId = 0;
    private string $action = 'approve';

    public function __construct(PDO $pdo, string $area) {
        parent::__construct($pdo, $area);
    }

    public function setDeviceId(int $id): void {
        $this->deviceId = $id;
    }

    /** @param string $action 'approve' oder 'revoke' */
    public function setAction(string $action): void {
        $this->action = $action;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            CsrfHelper::requireValidToken();
            $this->requireAnyPermission(['admin.access']);

            if ($this->deviceId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing device id']);
                return;
            }

            if (!in_array($this->action, ['approve', 'revoke'], true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action']);
                return;
            }

            $stmt = $this->pdo->prepare(
                'SELECT iot_devices_id, chip_id FROM mbc_iot_devices WHERE iot_devices_id = ?'
            );
            $stmt->execute([$this->deviceId]);
            $device = $stmt->fetch();

            if (!$device) {
                http_response_code(404);
                echo json_encode(['error' => 'Device not found']);
                return;
            }

            if ($this->action === 'approve') {
                $newStatus = 'approved';
                $update = $this->pdo->prepare(
                    "UPDATE mbc_iot_devices
                     SET provisioning_status = 'approved', approved_at = NOW(), updated_at = NOW()
                     WHERE iot_devices_id = ?"
                );
            } else {
                $newStatus = 'revoked';
                $update = $this->pdo->prepare(
                    "UPDATE mbc_iot_devices
                     SET provisioning_status = 'revoked', updated_at = NOW()
                     WHERE iot_devices_id = ?"
                );
            }
            $update->execute([$this->deviceId]);

            http_response_code(200);
            echo json_encode([
                'status' => 'ok',
                'device_id' => (int)$device['iot_devices_id'],
                'provisioning_status' => $newStatus,
            ]);

        } catch (\Throwable $e) {
            $this->handleError('Error updating device provisioning status', $e);
        }
    }
}
