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
 *    Optionaler Body `{"network_id": <int>}` ordnet das Gerät dabei einem
 *    bestimmten IoT-Netz (= einem Pi) zu — seit STORY-11.4 gibt es mehr als
 *    eine Zentrale. Ohne `network_id` bleibt das bei der Registrierung
 *    gesetzte (aktive) Netz bestehen.
 *  - revoke:  setzt `provisioning_status = 'revoked'`. Das Gerät wird ab sofort
 *    von allen Geräte-Endpoints mit 403 abgewiesen.
 */
class requestPostIotDeviceApprove extends RequestBase {
    private int $deviceId = 0;
    private string $action = 'approve';
    private array $data = [];

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

    /** JSON-Body der Anfrage (bereits dekodiert), z.B. ['network_id' => 2]. */
    public function setData(array $data): void {
        $this->data = $data;
    }

    /**
     * Liest `network_id` aus dem Body. Rückgabe: null = nicht angegeben,
     * int = gewünschtes Netz, false = ungültiger Wert.
     */
    private function requestedNetworkId(): int|false|null {
        if (!array_key_exists('network_id', $this->data) || $this->data['network_id'] === null || $this->data['network_id'] === '') {
            return null;
        }
        $raw = $this->data['network_id'];
        if (is_int($raw) && $raw > 0) {
            return $raw;
        }
        if (is_string($raw) && ctype_digit($raw) && (int)$raw > 0) {
            return (int)$raw;
        }
        return false;
    }

    private function networkExists(int $networkId): bool {
        $stmt = $this->pdo->prepare('SELECT 1 FROM mbc_iot_networks WHERE iot_networks_id = ?');
        $stmt->execute([$networkId]);
        return (bool)$stmt->fetchColumn();
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
                'SELECT iot_devices_id, chip_id, mbc_iot_networks FROM mbc_iot_devices WHERE iot_devices_id = ?'
            );
            $stmt->execute([$this->deviceId]);
            $device = $stmt->fetch();

            if (!$device) {
                http_response_code(404);
                echo json_encode(['error' => 'Device not found']);
                return;
            }

            $networkId = $device['mbc_iot_networks'] !== null ? (int)$device['mbc_iot_networks'] : null;

            if ($this->action === 'approve') {
                $requested = $this->requestedNetworkId();
                if ($requested === false) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid network_id']);
                    return;
                }
                if ($requested !== null && !$this->networkExists($requested)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Unknown network_id']);
                    return;
                }

                $newStatus = 'approved';
                if ($requested !== null) {
                    $networkId = $requested;
                    $update = $this->pdo->prepare(
                        "UPDATE mbc_iot_devices
                         SET provisioning_status = 'approved', approved_at = NOW(), updated_at = NOW(),
                             mbc_iot_networks = ?
                         WHERE iot_devices_id = ?"
                    );
                    $update->execute([$networkId, $this->deviceId]);
                } else {
                    $update = $this->pdo->prepare(
                        "UPDATE mbc_iot_devices
                         SET provisioning_status = 'approved', approved_at = NOW(), updated_at = NOW()
                         WHERE iot_devices_id = ?"
                    );
                    $update->execute([$this->deviceId]);
                }
            } else {
                $newStatus = 'revoked';
                $update = $this->pdo->prepare(
                    "UPDATE mbc_iot_devices
                     SET provisioning_status = 'revoked', updated_at = NOW()
                     WHERE iot_devices_id = ?"
                );
                $update->execute([$this->deviceId]);
            }

            http_response_code(200);
            echo json_encode([
                'status' => 'ok',
                'device_id' => (int)$device['iot_devices_id'],
                'provisioning_status' => $newStatus,
                'network_id' => $networkId,
            ]);

        } catch (\Throwable $e) {
            $this->handleError('Error updating device provisioning status', $e);
        }
    }
}
