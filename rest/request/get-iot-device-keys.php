<?php
declare(strict_types=1);
// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

if (!STOKEN) die('SEC');

/**
 * GET /iot/device-keys
 *
 * Liefert ausschliesslich die Zuordnung `deviceKey` -> `chipId` fuer alle
 * registrierten Geraete. Die Pi-Zentrale pflegt damit ihre lokale
 * `chip_id_map` und kann Messwerte aus MQTT-Topics dem richtigen Geraet
 * zuordnen (STORY-1.8 / STORY-1.11).
 *
 * Bewusst ein eigener, schmaler Endpunkt statt eines zweiten Auth-Pfades in
 * `GET /iot/devices`: Der Pi braucht nur diese zwei Felder. Die volle
 * Geraeteliste enthaelt Netzwerknamen, IPs und Firmware-Staende — ein
 * kompromittierter Pi haette damit ein Inventar der Flotte. Hier gibt es
 * nichts zu erweitern, was versehentlich mehr preisgibt.
 *
 * Authentifizierung: `X-Api-Key` eines Geraets mit `typ = 'pi'`.
 * Der gehärtete `GET /iot/devices` (Session-Auth, STORY-2.5) bleibt
 * unveraendert.
 *
 * Antworten:
 *  - HTTP 200  [{"chipId": "...", "deviceKey": "garten/klima"}, ...]
 *  - HTTP 401  ohne / mit ungueltigem X-Api-Key
 *  - HTTP 403  Key gehoert zu einem esp32/esp8266 oder das Geraet ist
 *              noch nicht freigegeben
 *
 * Geraete ohne Sensoren und Aktoren (z.B. der Pi selbst) haben keinen
 * ableitbaren deviceKey und tauchen nicht auf.
 */
class requestGetIotDeviceKeys extends RequestBase {

    public function __construct(PDO $pdo, string $area) {
        parent::__construct($pdo, $area);
    }

    public function execute(): void {
        try {
            // Setzt Content-Type und beantwortet 401/403 selbst.
            if (ApiKeyAuth::authenticateDevice($this->pdo, ['pi']) === null) {
                return;
            }

            // Ein Query statt zwei pro Geraet: der Pi ruft das alle 5 Minuten.
            $stmt = $this->pdo->query(
                'SELECT d.chip_id,
                        COALESCE(
                          (SELECT s.mqtt_topic FROM mbc_iot_sensoren s
                            WHERE s.mbc_iot_devices = d.iot_devices_id
                            ORDER BY s.iot_sensoren_id LIMIT 1),
                          (SELECT a.mqtt_topic_set FROM mbc_iot_aktoren a
                            WHERE a.mbc_iot_devices = d.iot_devices_id
                            ORDER BY a.iot_aktoren_id LIMIT 1)
                        ) AS sample_topic
                   FROM mbc_iot_devices d'
            );

            $out = [];
            foreach ($stmt->fetchAll() as $row) {
                $deviceKey = self::deviceKeyFromTopic($row['sample_topic'] ?? null);
                if ($deviceKey === null || empty($row['chip_id'])) {
                    continue;
                }
                $out[] = [
                    'chipId'    => $row['chip_id'],
                    'deviceKey' => $deviceKey,
                ];
            }

            http_response_code(200);
            echo json_encode($out);

        } catch (\Throwable $e) {
            $this->handleError('Error listing IoT device keys', $e);
        }
    }

    /**
     * Die mittleren zwei Pfadteile aus `pks/{projekt}/{bereich}/{messwert}`
     * ergeben den deviceKey, z.B. "garten/klima". Gleiche Ableitung wie
     * `deriveDeviceKey()` in get-iot-devices.php.
     */
    private static function deviceKeyFromTopic(?string $topic): ?string {
        if (!$topic) {
            return null;
        }
        $parts = explode('/', trim($topic, '/'));
        if (count($parts) < 3 || $parts[0] !== 'pks') {
            return null;
        }
        return $parts[1] . '/' . $parts[2];
    }
}
