<?php
// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Provisioning-Auth für die Geräte-Erstregistrierung (POST /iot/register).
 *
 * Modell: Fleet-Token + Admin-Claim (siehe docs/design/iot-device-auth.md).
 * Die Erst-Registrierung wird mit einem geteilten Fleet-Provisioning-Token
 * (Header `X-Provisioning-Token`) autorisiert. Server-seitig wird nur dessen
 * SHA-256-Hash gegen `mbc_iot_fleet_tokens` validiert; der Klartext wird nie
 * gespeichert und nie geloggt.
 */
class ProvisioningAuth {
    /**
     * Liest den `X-Provisioning-Token`-Header und prüft, ob er einem aktiven
     * Fleet-Token entspricht.
     *
     * @return int|null iot_fleet_tokens_id bei gültigem Token, sonst null.
     */
    public static function validateFleetToken(PDO $pdo): ?int {
        $token = $_SERVER['HTTP_X_PROVISIONING_TOKEN'] ?? '';
        if ($token === '') {
            return null;
        }

        $stmt = $pdo->prepare(
            "SELECT iot_fleet_tokens_id FROM mbc_iot_fleet_tokens
             WHERE token_hash = ? AND status = 'active'"
        );
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch();

        return $row ? (int)$row['iot_fleet_tokens_id'] : null;
    }
}
