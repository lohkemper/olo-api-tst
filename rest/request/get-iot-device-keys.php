<?php
declare(strict_types=1);
// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

if (!STOKEN) die('SEC');

require_once __DIR__ . '/../lib/DeviceKey.php';

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
 *
 * Die Ableitung selbst liegt in `lib/DeviceKey.php` und muss exakt mit
 * `internal/topics.Parse` auf der Pi-Zentrale uebereinstimmen — beide
 * schreiben in dieselbe `chip_id_map`. Die gemeinsame Fallsammlung steht in
 * `tests/fixtures/device-key-derivation.json`.
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

            // Ein Query statt einer Abfrage pro Geraet: der Pi ruft das alle
            // 5 Minuten. Die Sortierung ist nicht kosmetisch — sie stellt
            // genau die Reihenfolge her, in der die Pi-Seite ihre Kandidaten
            // durchgeht (Sensoren nach ID, dann Aktoren nach ID), damit beide
            // bei mehreren Topics denselben Schluessel waehlen.
            $stmt = $this->pdo->query(
                'SELECT iot_devices_id, chip_id, topic FROM (
                     SELECT d.iot_devices_id, d.chip_id,
                            s.mqtt_topic AS topic,
                            0 AS quelle, s.iot_sensoren_id AS pos
                       FROM mbc_iot_devices d
                       JOIN mbc_iot_sensoren s ON s.mbc_iot_devices = d.iot_devices_id
                     UNION ALL
                     SELECT d.iot_devices_id, d.chip_id,
                            a.mqtt_topic_set AS topic,
                            1 AS quelle, a.iot_aktoren_id AS pos
                       FROM mbc_iot_devices d
                       JOIN mbc_iot_aktoren a ON a.mbc_iot_devices = d.iot_devices_id
                 ) kandidaten
                 ORDER BY iot_devices_id, quelle, pos'
            );

            // Kandidaten je Geraet sammeln, damit ein unbrauchbares erstes
            // Topic nicht das ganze Geraet aus der Antwort wirft. Genau das
            // tat die fruehere Abfrage mit ihrem LIMIT 1.
            $kandidaten = [];
            $chipIds    = [];
            foreach ($stmt->fetchAll() as $row) {
                $id = $row['iot_devices_id'];
                $chipIds[$id]      = $row['chip_id'];
                $kandidaten[$id][] = $row['topic'];
            }

            $out = [];
            foreach ($kandidaten as $id => $topics) {
                $deviceKey = DeviceKey::fromTopics($topics);
                if ($deviceKey === null || empty($chipIds[$id])) {
                    continue;
                }
                $out[] = [
                    'chipId'    => $chipIds[$id],
                    'deviceKey' => $deviceKey,
                ];
            }

            http_response_code(200);
            echo json_encode($out);

        } catch (\Throwable $e) {
            $this->handleError('Error listing IoT device keys', $e);
        }
    }

}
