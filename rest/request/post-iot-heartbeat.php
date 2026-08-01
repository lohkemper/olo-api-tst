<?php
declare(strict_types=1);
// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

if (!STOKEN) die('SEC');

/**
 * POST /iot/heartbeat
 *
 * Aktualisiert den Online-Status und Heartbeat eines Geräts.
 * Authentifizierung über API-Key im Header (X-Api-Key).
 *
 * Dient zugleich als Provisioning-Poll: `pending`-Geräte dürfen heartbeaten
 * (sie zeigen dem Admin damit Lebenszeichen), erhalten aber noch keine
 * Netzwerk-Credentials. Erst nach Admin-Freigabe (`approved`) liefert die
 * Heartbeat-Response den `network`-Block — so bekommt die Firmware die
 * WLAN-Zugangsdaten ausschließlich über den authentifizierten Kanal (L2).
 * `revoked`-Geräte werden mit 403 abgewiesen.
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
            // requireApproved=false: pending-Geräte dürfen pollen.
            $device = ApiKeyAuth::authenticateDevice($this->pdo, null, false);
            if ($device === null) {
                return;
            }

            $deviceId = (int)$device['iot_devices_id'];
            $status = $device['provisioning_status'] ?? 'pending';

            if ($status === 'revoked') {
                http_response_code(403);
                echo json_encode(['error' => 'Device revoked', 'provisioning_status' => 'revoked']);
                return;
            }

            (new RateLimiter($this->pdo))->requireLimit(
                'iot/heartbeat:' . $deviceId, 60, 60
            );

            // Update heartbeat (auch für pending — Lebenszeichen fürs Admin-UI)
            $stmt = $this->pdo->prepare(
                'UPDATE mbc_iot_devices SET last_heartbeat = NOW(), online_status = ? WHERE iot_devices_id = ?'
            );
            $stmt->execute(['online', $deviceId]);

            $response = [
                'status' => 'ok',
                'device_id' => $deviceId,
                'provisioning_status' => $status,
                'timestamp' => date('c')
            ];

            // Netzwerk-Credentials nur an freigegebene Geräte.
            if ($status === 'approved') {
                $network = $this->fetchNetworkConfig($deviceId);
                if ($network !== null) {
                    $response['network'] = $network;
                }
            }

            echo json_encode($response);

        } catch (\Throwable $e) {
            $this->handleError('Error processing heartbeat', $e);
        }
    }

    /**
     * Lädt die WLAN/MQTT-Konfiguration des dem Gerät zugeordneten Netzwerks.
     */
    private function fetchNetworkConfig(int $deviceId): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT n.iot_ssid, n.iot_password, n.pi_local_ip, n.mqtt_port
             FROM mbc_iot_devices d
             JOIN mbc_iot_networks n ON d.mbc_iot_networks = n.iot_networks_id
             WHERE d.iot_devices_id = ?'
        );
        $stmt->execute([$deviceId]);
        $cfg = $stmt->fetch();
        if (!$cfg) {
            return null;
        }
        return [
            'iot_ssid'     => $cfg['iot_ssid'],
            'iot_password' => $cfg['iot_password'],
            'pi_local_ip'  => $cfg['pi_local_ip'],
            'mqtt_port'    => (int)$cfg['mqtt_port'],
        ];
    }
}
