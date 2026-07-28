<?php
declare(strict_types=1);
// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

/**
 * Ableitung des `device_key` aus einem MQTT-Topic.
 *
 * Der `device_key` ("garten/pflanze4") ist der Primaerschluessel der lokalen
 * `chip_id_map` auf der Pi-Zentrale. **Beide Seiten schreiben dort hinein**:
 * der Pi aus der MQTT-Anmeldung eines Geraets, dieser Server ueber
 * `GET /iot/device-keys`. Wer zuletzt schreibt, gewinnt.
 *
 * Deshalb muessen beide Seiten exakt denselben Schluessel bilden. Massgeblich
 * ist die Go-Seite (`internal/topics.Parse` in olo-go) — nicht aus Vorliebe,
 * sondern weil sie gewinnt: Der Pi bildet den Schluessel aus dem tatsaechlich
 * empfangenen Topic und schlaegt damit nach. Ein Schluessel, den der Pi nie
 * bildet, findet in der Tabelle nie einen Partner.
 *
 * Die Faelle stehen in `tests/fixtures/device-key-derivation.json` und werden
 * von `tests/unit-test-device-key-derivation.php` geprueft. Dieselbe Tabelle
 * liegt auf der Go-Seite.
 *
 * Historie: Bis 2026-07-28 war die Ableitung hier grosszuegiger als drueben —
 * sie trimmte Schraegstriche weg, akzeptierte drei Segmente und liess leere
 * Segmente durch. Vier Topic-Formen ergaben damit einen Schluessel, den der Pi
 * nie nachschlaegt. Siehe STORY-1.11.
 */
final class DeviceKey {

    /**
     * Liefert "{projekt}/{geraet}" oder null, wenn das Topic nicht dem
     * PKS-Schema `pks/{projekt}/{geraet}/{komponente}[/{detail}]` folgt.
     *
     * Bewusst **ohne** trim(): Der Pi parst das Topic so, wie es ueber MQTT
     * ankommt. Ein fuehrender oder abschliessender Schraegstrich macht das
     * Topic dort unbrauchbar, also darf er es hier auch.
     */
    public static function fromTopic(?string $topic): ?string {
        if ($topic === null || $topic === '') {
            return null;
        }
        $parts = explode('/', $topic);
        if (count($parts) < 4 || $parts[0] !== 'pks') {
            return null;
        }
        foreach ($parts as $segment) {
            if ($segment === '') {
                return null;
            }
        }
        return $parts[1] . '/' . $parts[2];
    }

    /**
     * Erster Topic der Liste, der einen Schluessel ergibt — nicht der erste
     * Topic ueberhaupt.
     *
     * Der Unterschied zaehlt: Frueher nahm die Abfrage per `LIMIT 1` genau den
     * ersten Sensor. War dessen Topic unbrauchbar, fiel das ganze Geraet aus
     * der Antwort, obwohl ein zweiter Sensor den Schluessel geliefert haette.
     * Der Pi macht es andersherum (`Announcement.DeviceKey()`: Sensoren der
     * Reihe nach, dann Aktoren) — und der Pi hat recht, siehe oben.
     *
     * @param iterable<int, string|null> $topics in der Reihenfolge, die der Pi
     *        verwendet: erst Sensoren, dann Aktoren, je nach ID aufsteigend.
     */
    public static function fromTopics(iterable $topics): ?string {
        foreach ($topics as $topic) {
            $key = self::fromTopic($topic);
            if ($key !== null) {
                return $key;
            }
        }
        return null;
    }
}
