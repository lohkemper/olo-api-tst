<?php
declare(strict_types=1);
// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

/**
 * Prueft `DeviceKey` gegen die Fallsammlung, die auch die Pi-Zentrale prueft.
 *
 * Aufruf:  php tests/unit-test-device-key-derivation.php
 * Exit 0 = alle Faelle gleich, Exit 1 = mindestens einer weicht ab.
 *
 * Kein Netz, keine Datenbank, keine Session — das ist Absicht. Alle anderen
 * Tests hier sind Integrationstests gegen den Live-Server; dieser laeuft im
 * Repository, weil die Regel, die er prueft, sich beim Deploy nicht aendert.
 *
 * Warum das eine eigene Pruefung verdient: Der `device_key` entsteht zweimal
 * unabhaengig — hier und in `internal/topics.Parse` (olo-go). Beide schreiben
 * in dieselbe `chip_id_map` auf dem Pi, wer zuletzt schreibt gewinnt. Am
 * 2026-07-28 wichen sie in vier Topic-Formen voneinander ab. Aufgefallen ist
 * das nur, weil jemand beide Dateien nebeneinandergelegt hat.
 */

require_once __DIR__ . '/../rest/lib/DeviceKey.php';

$fixture = __DIR__ . '/fixtures/device-key-derivation.json';
$raw = @file_get_contents($fixture);
if ($raw === false) {
    fwrite(STDERR, "Fallsammlung nicht lesbar: $fixture\n");
    exit(2);
}

$contract = json_decode($raw, true);
if (!is_array($contract) || empty($contract['cases'])) {
    fwrite(STDERR, "Fallsammlung leer oder unlesbar — eine leere Tabelle bestuende stillschweigend\n");
    exit(2);
}

$pass = 0;
$fail = 0;

foreach ($contract['cases'] as $case) {
    $topic    = $case['topic'];
    $erwartet = $case['deviceKey'] ?? null;   // null = muss abgelehnt werden
    $ist      = DeviceKey::fromTopic($topic);

    if ($ist === $erwartet) {
        $pass++;
        printf("  ok    %-40s -> %s\n", var_export($topic, true),
               $ist === null ? 'abgelehnt' : $ist);
        continue;
    }

    $fail++;
    printf("  FAIL  %-40s -> %s, erwartet %s\n",
           var_export($topic, true),
           $ist === null ? 'abgelehnt' : var_export($ist, true),
           $erwartet === null ? 'abgelehnt' : var_export($erwartet, true));
    if (!empty($case['why'])) {
        printf("        Grund: %s\n", $case['why']);
    }
    if (!empty($case['divergence'])) {
        printf("        Bekannte Abweichung: %s\n", $case['divergence']);
    }
}

// Durchfallen auf den naechsten brauchbaren Topic. Steht nicht in der
// Fallsammlung, weil es keine Eigenschaft eines einzelnen Topics ist, sondern
// der Reihenfolge — und genau diese Reihenfolge hat die alte Abfrage mit ihrem
// LIMIT 1 uebersprungen.
$reihenfolge = [
    'Erster Topic unbrauchbar, zweiter traegt'
        => [['pks/garten', 'pks/garten/pflanze4/boden'], 'garten/pflanze4'],
    'Leerer Eintrag wird uebersprungen'
        => [[null, '', 'pks/garten/klima/temperatur'], 'garten/klima'],
    'Erster brauchbarer gewinnt'
        => [['pks/garten/klima/temperatur', 'pks/garten/licht/ldr'], 'garten/klima'],
    'Kein brauchbarer Topic'
        => [['pks/garten', '/pks/a/b/c'], null],
    'Gar keine Topics (z.B. der Pi selbst)'
        => [[], null],
];

foreach ($reihenfolge as $name => [$topics, $erwartet]) {
    $ist = DeviceKey::fromTopics($topics);
    if ($ist === $erwartet) {
        $pass++;
        printf("  ok    %s\n", $name);
    } else {
        $fail++;
        printf("  FAIL  %s -> %s, erwartet %s\n", $name,
               var_export($ist, true), var_export($erwartet, true));
    }
}

printf("\n%d/%d Faelle gruen\n", $pass, $pass + $fail);

if ($fail > 0) {
    fwrite(STDERR, "\nAbweichung zur Pi-Seite. Beide Seiten muessen denselben Schluessel\n"
                 . "bilden, sonst schreiben sie sich gegenseitig die chip_id_map um.\n"
                 . "Siehe contracts/README.md im PKS-Repository.\n");
    exit(1);
}
exit(0);
