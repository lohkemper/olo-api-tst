<?php
declare(strict_types=1);
// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

if (!STOKEN) die('SEC');

/**
 * GET /iot/networks         — Alle Netzwerke (ohne Passwörter)
 * GET /iot/networks/{id}    — Einzelnes Netzwerk
 */
class requestGetIotNetworks extends RequestBase {
    private array $request = [];

    public function __construct(PDO $pdo, string $area) {
        parent::__construct($pdo, $area);
    }

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            $this->requireAuth();
            header('Content-Type: application/json; charset=utf-8');

            $networkId = $this->request['id'] ?? null;

            if ($networkId) {
                $stmt = $this->pdo->prepare(
                    'SELECT iot_networks_id, name, iot_ssid, pi_local_ip, mqtt_port, is_active, created_at, updated_at
                     FROM mbc_iot_networks WHERE iot_networks_id = ?'
                );
                $stmt->execute([(int)$networkId]);
                $network = $stmt->fetch();

                if (!$network) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Network not found']);
                    return;
                }

                // Count devices in this network
                $stmt = $this->pdo->prepare('SELECT COUNT(*) AS cnt FROM mbc_iot_devices WHERE mbc_iot_networks = ?');
                $stmt->execute([(int)$networkId]);
                $network['device_count'] = (int)$stmt->fetch()['cnt'];

                echo json_encode($network);
            } else {
                $stmt = $this->pdo->prepare(
                    'SELECT iot_networks_id, name, iot_ssid, pi_local_ip, mqtt_port, is_active, created_at, updated_at
                     FROM mbc_iot_networks ORDER BY name'
                );
                $stmt->execute();
                echo json_encode($stmt->fetchAll());
            }

        } catch (\Throwable $e) {
            $this->handleError('Error fetching IoT networks', $e);
        }
    }
}
