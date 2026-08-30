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
     * @param bool $requireApproved wenn true (Default), muss das Gerät
     *             `provisioning_status = 'approved'` sein — `pending`/`revoked`
     *             ergeben 403 (schließt L3: nicht freigegebene Geräte dürfen
     *             keine Daten senden). Endpoints, die `pending` erlauben (z.B.
     *             Heartbeat-Polling), übergeben false und prüfen den Status selbst.
     * @return array|null Device-Row (iot_devices_id, chip_id, typ,
     *                    provisioning_status) oder null, wenn Fehler — Response
     *                    wurde dann bereits gesendet.
     */
    public static function authenticateDevice(PDO $pdo, ?array $allowedTypes = null, bool $requireApproved = true): ?array {
        header('Content-Type: application/json; charset=utf-8');

        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if ($apiKey === '') {
            http_response_code(401);
            echo json_encode(['error' => 'Missing X-Api-Key header']);
            return null;
        }

        $stmt = $pdo->prepare(
            'SELECT iot_devices_id, chip_id, typ, provisioning_status FROM mbc_iot_devices WHERE api_key_hash = ?'
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

        if ($requireApproved && ($device['provisioning_status'] ?? '') !== 'approved') {
            http_response_code(403);
            echo json_encode([
                'error' => 'Device not approved',
                'provisioning_status' => $device['provisioning_status'] ?? 'unknown',
            ]);
            return null;
        }

        self::touchHeartbeat($pdo, $device);

        return $device;
    }

    /**
     * Jeder erfolgreich authentifizierte Request ist ein Lebenszeichen — nicht
     * nur die Daten-Endpoints. Vorher setzten pi-sync/data-sync/heartbeat den
     * Heartbeat jeweils selbst; der Pi galt dadurch als offline, sobald 5 min
     * keine neuen Messwerte flossen, obwohl er jede Sync-Runde authentifiziert
     * GET /iot/device-keys abruft (STORY-2.6, Vorfall 2026-08-30).
     *
     * `revoked` bleibt ausgenommen: ein gesperrtes Gerät soll im Admin-UI
     * nicht als online erscheinen, auch wenn sein alter Key noch anklopft.
     */
    private static function touchHeartbeat(PDO $pdo, array $device): void {
        if (($device['provisioning_status'] ?? '') === 'revoked') {
            return;
        }
        $pdo->prepare(
            'UPDATE mbc_iot_devices SET last_heartbeat = NOW(), online_status = ? WHERE iot_devices_id = ?'
        )->execute(['online', (int)$device['iot_devices_id']]);
    }
}
