<?php
declare(strict_types=1);
// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

if (!STOKEN) die('SEC');

/**
 * PUT /iot/networks/{id}
 *
 * Aktualisiert ein vorhandenes Netzwerk. Nur uebermittelte Felder werden
 * geschrieben (PATCH-Semantik). Wenn is_active=1 gesetzt wird, werden alle
 * anderen Netze automatisch deaktiviert.
 *
 * Erfordert Session-Authentifizierung (Admin).
 */
class requestPutIotNetworks extends RequestBase {
    private array $request = [];
    private array $data = [];

    private const ALLOWED_FIELDS = [
        'name', 'iot_ssid', 'iot_password',
        'inet_ssid', 'inet_password',
        'pi_local_ip', 'mqtt_port', 'is_active'
    ];

    public function __construct(PDO $pdo, string $area) {
        parent::__construct($pdo, $area);
    }

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function setData(array $data): void {
        $this->data = $data;
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

            $updates = $this->collectUpdates();
            if (empty($updates['fields'])) {
                http_response_code(400);
                echo json_encode(['error' => 'No updatable fields provided']);
                return;
            }

            $this->pdo->beginTransaction();

            // If activating this network, deactivate all others first
            if (isset($this->data['is_active']) && (int)$this->data['is_active'] === 1) {
                $stmt = $this->pdo->prepare('UPDATE mbc_iot_networks SET is_active = 0 WHERE iot_networks_id != ?');
                $stmt->execute([$networkId]);
            }

            $sql = 'UPDATE mbc_iot_networks SET ' . implode(', ', $updates['fields'])
                 . ', updated_at = NOW() WHERE iot_networks_id = ?';
            $params = $updates['params'];
            $params[] = $networkId;

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            $this->pdo->commit();

            // Return updated network
            $stmt = $this->pdo->prepare(
                'SELECT iot_networks_id, name, iot_ssid, pi_local_ip, mqtt_port, is_active, created_at, updated_at
                 FROM mbc_iot_networks WHERE iot_networks_id = ?'
            );
            $stmt->execute([$networkId]);

            http_response_code(200);
            echo json_encode($stmt->fetch());

        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->handleError('Error updating IoT network', $e);
        }
    }

    private function collectUpdates(): array {
        $fields = [];
        $params = [];

        foreach (self::ALLOWED_FIELDS as $field) {
            if (!array_key_exists($field, $this->data)) {
                continue;
            }
            $value = $this->data[$field];
            if (in_array($field, ['mqtt_port', 'is_active'], true)) {
                $params[] = (int)$value;
            } else {
                $params[] = trim((string)$value);
            }
            $fields[] = "`{$field}` = ?";
        }

        return ['fields' => $fields, 'params' => $params];
    }
}
