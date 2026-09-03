<?php
declare(strict_types=1);
// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

/**
 * Validierung des Geraete-Descriptors Schema v2 (EPIC-10, STORY-10.3).
 *
 * Die Regeln existieren zweimal: hier und in `internal/provisioning`
 * (ParseDescriptorV2, olo-go). Beide Seiten pruefen dieselbe Fallsammlung
 * `tests/fixtures/descriptor-v2.json`; die kanonische Kopie liegt in
 * `contracts/` des PKS-Repositories, `check-contract-drift.sh` vergleicht.
 * Das ist derselbe Drift-Schutz, der beim device_key erst NACH drei stillen
 * Monaten eingezogen wurde (STORY-1.11) — hier ist er von Anfang an da.
 *
 * Kernpunkte von v2:
 *  - Die Identitaet ist DEKLARIERT: `mqtt_base` = "pks/{projekt}/{geraet}",
 *    der device_key ist mqtt_base ohne "pks/" — keine Ableitung aus
 *    Sensor-Topics mehr (das war die driftende Stelle von v1).
 *  - Topics werden ausschliesslich abgeleitet:
 *    {mqtt_base}/{komponente.id}/{value_key}. Es gibt kein topic-Feld.
 *  - Jede Value ist typisiert (number|string|boolean); enum und min/max
 *    schliessen sich aus, enum-Eintraege sind typkonform.
 */
final class DescriptorV2 {

    public const SCHEMA = 2;

    /**
     * Prueft einen dekodierten v2-Body vollstaendig. Liefert null wenn
     * gueltig, sonst die Fehlermeldung fuer die 400-Antwort. Alles, was die
     * Fallsammlung ablehnt, muss hier einen Fehler ergeben.
     */
    public static function validate(array $data): ?string {
        if ((int)($data['schema'] ?? 0) !== self::SCHEMA) {
            return 'Unsupported schema version ' . (string)($data['schema'] ?? 0);
        }
        foreach (['chip_id', 'name', 'typ', 'firmware_version', 'mqtt_base'] as $field) {
            if (empty($data[$field]) || !is_string($data[$field])) {
                return "Missing required field: {$field}";
            }
        }

        $baseError = self::validateMqttBase($data['mqtt_base']);
        if ($baseError !== null) {
            return $baseError;
        }

        $seen = [];
        foreach (['sensoren' => 'Sensor', 'aktoren' => 'Aktor'] as $listKey => $label) {
            $entries = $data[$listKey] ?? [];
            if (!is_array($entries)) {
                return "{$listKey} must be a list";
            }
            foreach ($entries as $idx => $component) {
                $error = self::validateComponent($component, "{$label} [{$idx}]", $seen);
                if ($error !== null) {
                    return $error;
                }
            }
        }
        return null;
    }

    /**
     * device_key aus der deklarierten Identitaet. Nur nach erfolgreichem
     * validate() aufrufen.
     */
    public static function deviceKey(array $data): string {
        return substr((string)$data['mqtt_base'], strlen('pks/'));
    }

    /**
     * Alle abgeleiteten Topics eines gueltigen Descriptors, sortiert.
     * {mqtt_base}/{komponente.id}/{value_key} — die einzige Topic-Quelle.
     *
     * @return string[]
     */
    public static function deriveTopics(array $data): array {
        $topics = [];
        $base = (string)$data['mqtt_base'];
        foreach (['sensoren', 'aktoren'] as $listKey) {
            foreach ($data[$listKey] ?? [] as $component) {
                foreach (array_keys($component['values'] ?? []) as $valueKey) {
                    $topics[] = $base . '/' . $component['id'] . '/' . $valueKey;
                }
            }
        }
        sort($topics);
        return $topics;
    }

    /**
     * mqtt_base: exakt pks/{projekt}/{geraet}, jedes Segment plain und
     * nicht leer — dieselbe Form, die der device-key-Kontrakt (v1) fuer
     * empfangene Topics verlangt.
     */
    private static function validateMqttBase(string $base): ?string {
        $parts = explode('/', $base);
        if (count($parts) !== 3) {
            return "mqtt_base '{$base}' must have exactly 3 segments pks/{projekt}/{geraet}";
        }
        if ($parts[0] !== 'pks') {
            return "mqtt_base '{$base}' must start with pks/";
        }
        foreach ([$parts[1], $parts[2]] as $segment) {
            if (!self::isPlainSegment($segment)) {
                return "mqtt_base '{$base}' contains an empty or unsafe segment";
            }
        }
        return null;
    }

    /**
     * @param array<string, bool> $seen Komponenten-Ids ueber sensoren UND
     *        aktoren hinweg — eine doppelte Id wuerde denselben Topic-Raum
     *        zweimal vergeben.
     */
    private static function validateComponent(mixed $component, string $where, array &$seen): ?string {
        if (!is_array($component)) {
            return "{$where} is not an object";
        }
        $id = $component['id'] ?? '';
        if (!is_string($id) || !self::isPlainSegment($id)) {
            return "{$where}: id is not a plain topic segment";
        }
        if (isset($seen[$id])) {
            return "{$where}: component id '{$id}' declared twice — the derived topics would collide";
        }
        $seen[$id] = true;

        if (empty($component['name']) || !is_string($component['name'])) {
            return "{$where}: missing name";
        }
        $values = $component['values'] ?? [];
        if (!is_array($values) || count($values) === 0) {
            return "{$where}: declares no values — it would have no topic at all";
        }
        foreach ($values as $valueKey => $value) {
            $error = self::validateValue((string)$valueKey, $value, "{$where} value '{$valueKey}'");
            if ($error !== null) {
                return $error;
            }
        }
        return null;
    }

    private static function validateValue(string $key, mixed $value, string $where): ?string {
        if (!self::isPlainSegment($key)) {
            return "{$where}: key is not a plain topic segment";
        }
        if (!is_array($value)) {
            return "{$where}: is not an object";
        }
        $typ = $value['type'] ?? '';
        if (!in_array($typ, ['number', 'string', 'boolean'], true)) {
            return "{$where}: type must be number, string or boolean";
        }
        if (isset($value['intervall']) && (!is_int($value['intervall']) || $value['intervall'] <= 0)) {
            return "{$where}: intervall must be > 0 seconds";
        }

        $hasEnum = isset($value['enum']) && is_array($value['enum']) && count($value['enum']) > 0;
        $hasRange = isset($value['min']) || isset($value['max']);
        if ($hasEnum && $hasRange) {
            return "{$where}: enum and min/max are mutually exclusive";
        }
        if ($hasRange) {
            if ($typ !== 'number') {
                return "{$where}: min/max is only defined for type number";
            }
            foreach (['min', 'max'] as $bound) {
                if (isset($value[$bound]) && !is_int($value[$bound]) && !is_float($value[$bound])) {
                    return "{$where}: {$bound} must be a number";
                }
            }
            if (isset($value['min'], $value['max']) && $value['min'] > $value['max']) {
                return "{$where}: min exceeds max";
            }
        }
        if ($hasEnum) {
            foreach ($value['enum'] as $entry) {
                if (!self::enumEntryMatchesType($entry, $typ)) {
                    return "{$where}: enum entry does not match type {$typ}";
                }
            }
        }
        return null;
    }

    /**
     * enum-Eintraege typkonform zum deklarierten type — Zahlen als Zahlen,
     * Strings als Strings (Nutzerentscheidung 2026-09-03).
     */
    private static function enumEntryMatchesType(mixed $entry, string $typ): bool {
        return match ($typ) {
            'number'  => is_int($entry) || is_float($entry),
            'string'  => is_string($entry),
            'boolean' => is_bool($entry),
            default   => false,
        };
    }

    /**
     * Spiegel von isPlainSegment in olo-go: alles, was die Form eines Topics
     * veraendern wuerde statt einen Platz darin zu fuellen, wird abgelehnt.
     */
    private static function isPlainSegment(string $segment): bool {
        if ($segment === '') {
            return false;
        }
        return preg_match('~[+#/\s]~', $segment) !== 1;
    }
}
