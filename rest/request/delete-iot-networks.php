<?php
declare(strict_types=1);
// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

if (!STOKEN) die('SEC');

/**
 * DELETE /iot/networks/{id}
 *
 * Loescht ein Netzwerk, sofern keine Devices mehr referenzieren.
 * - HTTP 204 bei Erfolg
 * - HTTP 404 wenn ID nicht existiert
 * - HTTP 409 wenn noch Devices haengen (mit device_count im Body)
 *
 * Erfordert Session-Authentifizierung (Admin).
 */
class requestDeleteIotNetworks extends RequestBase {
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
            $this->requireAuth();

            $networkId = (int)($this->request['id'] ?? 0);
            if ($networkId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing or invalid network id']);
                return;
            }

            // Verify network exists
            $stmt = $this->pdo->prepare('SELECT iot_networks_id FROM mbc_iot_networks WHERE iot_networks_id = ?');
            $stmt->execute([$networkId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Network not found']);
                return;
            }

            // Safety check: refuse delete if devices still reference this network
            $stmt = $this->pdo->prepare('SELECT COUNT(*) AS cnt FROM mbc_iot_devices WHERE mbc_iot_networks = ?');
            $stmt->execute([$networkId]);
            $deviceCount = (int)$stmt->fetch()['cnt'];

            if ($deviceCount > 0) {
                http_response_code(409);
                echo json_encode([
                    'error' => 'Network still referenced by devices',
                    'device_count' => $deviceCount
                ]);
                return;
            }

            $stmt = $this->pdo->prepare('DELETE FROM mbc_iot_networks WHERE iot_networks_id = ?');
            $stmt->execute([$networkId]);

            http_response_code(204);

        } catch (\Throwable $e) {
            $this->handleError('Error deleting IoT network', $e);
        }
    }
}
