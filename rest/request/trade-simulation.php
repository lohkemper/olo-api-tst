<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Deterministische Kurs-Simulation für das Trade-Modul.
 *
 * Tageskurse als Random Walk: close = prev * (1 + drift + volatility * z).
 * z kommt aus einer Irwin-Hall-Approximation der Normalverteilung
 * (Summe von 4 Uniform-Zügen) — bewusst OHNE log/cos (Box-Muller), weil
 * transzendente Funktionen nicht auf allen Plattformen bit-identisch sind.
 * round(..., 4) nach jedem Schritt kappt Float-Akkumulationsdrift.
 *
 * Gleicher Seed + gleiche Parameter + gleiches Datum => exakt gleiche Serie,
 * für alle User, ohne Cron und ohne Preis-Tabelle (Hybrid-Pfad: source='live'
 * liest später stattdessen aus mbc_trade_prices, Format bleibt identisch).
 */

/**
 * Untere 32 Bit von $x * $y — überlauffrei via 16-Bit-Hälften.
 *
 * WICHTIG: Zwei volle 32-Bit-Werte direkt zu multiplizieren überläuft
 * PHP_INT_MAX (bis ~1.8e19 > 2^63-1) → PHP kippt auf Float, das
 * anschließende &-Masking wirft pro Aufruf eine Precision-Warnung und
 * liefert falsche Bits. Deshalb: xLo*y (≤ 2.8e14) + (xHi*yLo << 16)
 * (≤ 2.8e14) — beide sicher im int64; Anteile ab Bit 32 fallen mod 2^32
 * ohnehin weg.
 */
function tradeSimMul32(int $x, int $y): int {
    $xLo = $x & 0xFFFF;
    $xHi = ($x >> 16) & 0xFFFF;
    return ($xLo * $y + (($xHi * ($y & 0xFFFF)) << 16)) & 0xFFFFFFFF;
}

/**
 * mulberry32-PRNG. PHP-Ints sind 64-bit — nach jeder Operation wird deshalb
 * explizit auf 32 Bit maskiert, damit die Sequenz plattformstabil ist;
 * Multiplikationen laufen über tradeSimMul32 (siehe dort).
 *
 * @return Closure(): float  Uniform-Zug in [0, 1)
 */
function tradeSimMulberry32(int $seed): Closure {
    $a = $seed & 0xFFFFFFFF;
    return function () use (&$a): float {
        $a = ($a + 0x6D2B79F5) & 0xFFFFFFFF;
        $t = tradeSimMul32($a ^ ($a >> 15), ($a | 1) & 0xFFFFFFFF);
        $m = tradeSimMul32(($t ^ ($t >> 7)) & 0xFFFFFFFF, ($t | 61) & 0xFFFFFFFF);
        $t = ((($t + $m) & 0xFFFFFFFF) ^ $t) & 0xFFFFFFFF;
        return (($t ^ ($t >> 14)) & 0xFFFFFFFF) / 4294967296.0;
    };
}

/**
 * Komplette Tageskurs-Serie von sim_start_date bis $toDate (inklusive).
 * Nur Werktage; Wochenenden werden OHNE PRNG-Zug und ohne Kursschritt
 * übersprungen. Der erste Werktag trägt den Startkurs unverändert.
 *
 * @return array<int, array{date: string, close: float}>
 */
function tradeSimSeries(
    int $seed,
    string $simStartDate,
    float $startPrice,
    float $drift,
    float $volatility,
    string $toDate
): array {
    $rand = tradeSimMulberry32($seed);
    $sqrt3 = sqrt(3.0);

    $day = new DateTimeImmutable($simStartDate);
    $end = new DateTimeImmutable($toDate);
    if ($day > $end) {
        return [];
    }

    $series = [];
    $price = round($startPrice, 4);
    $first = true;

    while ($day <= $end) {
        $weekday = (int)$day->format('N'); // 1 = Mo ... 7 = So
        if ($weekday <= 5) {
            if ($first) {
                $first = false;
            } else {
                // Irwin-Hall: Summe von 4 Uniforms, auf Varianz 1 skaliert
                $z = (($rand() + $rand() + $rand() + $rand()) - 2.0) * $sqrt3;
                $price = round($price * (1.0 + $drift + $volatility * $z), 4);
                if ($price < 0.01) {
                    $price = 0.01;
                }
            }
            $series[] = ['date' => $day->format('Y-m-d'), 'close' => $price];
        }
        $day = $day->modify('+1 day');
    }

    return $series;
}

/**
 * Range-Kürzel => frühestes Datum (relativ zu $toDate). null = 'max'
 * (komplette Serie ab sim_start_date). Unbekannte Range fällt auf '3m'.
 */
function tradeSimRangeFromDate(string $range, string $toDate): ?string {
    $to = new DateTimeImmutable($toDate);
    return match ($range) {
        'max' => null,
        '1m'  => $to->modify('-1 month')->format('Y-m-d'),
        '6m'  => $to->modify('-6 months')->format('Y-m-d'),
        '1y'  => $to->modify('-1 year')->format('Y-m-d'),
        default => $to->modify('-3 months')->format('Y-m-d'),
    };
}
