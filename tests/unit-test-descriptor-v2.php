<?php
declare(strict_types=1);
// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

/**
 * Prueft `DescriptorV2` gegen die Fallsammlung, die auch die Pi-Zentrale
 * prueft (internal/provisioning/descriptor_contract_test.go in olo-go).
 *
 * Aufruf:  php tests/unit-test-descriptor-v2.php
 * Exit 0 = alle Faelle gleich, Exit 1 = mindestens einer weicht ab.
 *
 * Kein Netz, keine Datenbank, keine Session — wie beim device-key-Kontrakt:
 * die Regel, die hier geprueft wird, aendert sich beim Deploy nicht. Beide
 * Seiten muessen denselben Descriptor annehmen bzw. ablehnen und dieselben
 * Topics ableiten, sonst registriert der Pi Geraete, die der Server ablehnt —
 * oder umgekehrt.
 */

require_once __DIR__ . '/../rest/lib/DescriptorV2.php';

$fixture = __DIR__ . '/fixtures/descriptor-v2.json';
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
$rejections = 0;

foreach ($contract['cases'] as $case) {
    $name     = $case['name'];
    $erwartet = $case['deviceKey'] ?? null;   // null = muss abgelehnt werden
    $error    = DescriptorV2::validate($case['descriptor']);

    if ($erwartet === null) {
        $rejections++;
        if ($error !== null) {
            $pass++;
            printf("  ok    %-50s abgelehnt: %s\n", $name, $error);
        } else {
            $fail++;
            printf("  FAIL  %-50s akzeptiert, muss abgelehnt werden\n", $name);
            printf("        Grund: %s\n", $case['why'] ?? '');
        }
        continue;
    }

    if ($error !== null) {
        $fail++;
        printf("  FAIL  %-50s abgelehnt (%s), erwartet key %s\n", $name, $error, $erwartet);
        continue;
    }

    $key = DescriptorV2::deviceKey($case['descriptor']);
    if ($key !== $erwartet) {
        $fail++;
        printf("  FAIL  %-50s key %s, erwartet %s\n", $name, $key, $erwartet);
        continue;
    }

    $topics = DescriptorV2::deriveTopics($case['descriptor']);
    $erwarteteTopics = $case['topics'] ?? [];
    sort($erwarteteTopics);
    if ($topics !== $erwarteteTopics) {
        $fail++;
        printf("  FAIL  %-50s Topics weichen ab:\n        ist:  %s\n        soll: %s\n",
               $name, implode(', ', $topics), implode(', ', $erwarteteTopics));
        continue;
    }

    $pass++;
    printf("  ok    %-50s -> %s (%d Topics)\n", $name, $key, count($topics));
}

// Waechter ueber die Tabelle selbst — die Ablehnungsfaelle sind der Grund,
// warum der Kontrakt existiert (Spiegel von TestContractRejectsWhatItMust).
if ($rejections < 10) {
    $fail++;
    printf("  FAIL  Fallsammlung traegt nur %d Ablehnungsfaelle, erwartet mindestens 10\n", $rejections);
}

printf("\n%d/%d Faelle gruen\n", $pass, $pass + $fail);

if ($fail > 0) {
    fwrite(STDERR, "\nAbweichung zur Pi-Seite. Beide Seiten muessen denselben Descriptor\n"
                 . "annehmen bzw. ablehnen und dieselben Topics ableiten.\n"
                 . "Siehe contracts/README.md im PKS-Repository.\n");
    exit(1);
}
exit(0);
