// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary
<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /iot/networks
 *
 * Erstellt ein neues Netzwerk (IoT-WLAN + Internet-WLAN + Pi-IP).
 * Erfordert Session-Authentifizierung (Admin).
 */
class requestPostIotNetworks extends RequestBase {
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

            // Require authentication
            $this->requireAuth();

            // Validate required fields
            $requiredFields = ['name', 'iot_ssid', 'iot_password', 'pi_local_ip'];
            foreach ($requiredFields as $field) {
                if (empty($this->data[$field])) {
                    http_response_code(400);
                    echo json_encode(['error' => "Missing required field: {$field}"]);
                    return;
                }
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO mbc_iot_networks (name, iot_ssid, iot_password, inet_ssid, inet_password, pi_local_ip, mqtt_port, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                trim($this->data['name']),
                trim($this->data['iot_ssid']),
                trim($this->data['iot_password']),
                trim($this->data['inet_ssid'] ?? ''),
                trim($this->data['inet_password'] ?? ''),
                trim($this->data['pi_local_ip']),
                (int)($this->data['mqtt_port'] ?? 1883),
                (int)($this->data['is_active'] ?? 1)
            ]);

            $networkId = (int)$this->pdo->lastInsertId();

            http_response_code(201);
            echo json_encode([
                'iot_networks_id' => $networkId,
                'message' => 'Network created successfully'
            ]);

        } catch (\Throwable $e) {
            $this->handleError('Error creating IoT network', $e);
        }
    }
}
