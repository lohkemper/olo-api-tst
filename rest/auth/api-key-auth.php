<?php
// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Zentraler Authentifizierungs-Helper fuer IoT-Endpoints, die per
 * X-Api-Key-Header authentifiziert werden. Vereinheitlicht den zuvor in
 * pi-sync / data-sync / heartbeat duplizierten Lookup-Code.
 *
 * Validiert via SHA-256-Hash gegen `mbc_iot_devices.api_key_hash`
 * (TASK-2.5.2).
 */
class ApiKeyAuth {
    /**
     * Authentifiziert den X-Api-Key-Header.
     *
     * @param PDO $pdo
     * @param string[]|null $allowedTypes optionaler Typ-Filter (z.B. ['pi'])
     * @return array|null Device-Row (iot_devices_id, chip_id, typ) oder null,
     *                    wenn Fehler — Response wurde dann bereits gesendet.
     */
    public static function authenticateDevice(PDO $pdo, ?array $allowedTypes = null): ?array {
        header('Content-Type: application/json; charset=utf-8');

        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if ($apiKey === '') {
            http_response_code(401);
            echo json_encode(['error' => 'Missing X-Api-Key header']);
            return null;
        }

        $stmt = $pdo->prepare(
            'SELECT iot_devices_id, chip_id, typ FROM mbc_iot_devices WHERE api_key_hash = ?'
        );
        $stmt->execute([hash('sha256', $apiKey)]);
        $device = $stmt->fetch();

        if (!$device) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid API key']);
            return null;
        }

        if ($allowedTypes !== null && !in_array($device['typ'], $allowedTypes, true)) {
            http_response_code(403);
            echo json_encode([
                'error' => 'Device type not allowed for this endpoint',
                'allowed_types' => $allowedTypes,
            ]);
            return null;
        }

        return $device;
    }
}
