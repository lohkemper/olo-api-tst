<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Geteilte Papertrading-Helfer (Trade-Modul V2).
 * Include-Reihenfolge: NACH trade-simulation.php (nutzt tradeSimSeries).
 */

const TRADE_ORDER_FEE = 1.00;        // EUR flat pro Order (Produktentscheidung)
const TRADE_STARTING_CASH = 10000.00; // EUR virtuelles Startkapital

/** Depot des Users holen — legt es beim ersten Zugriff an (ein Depot pro User). */
function tradePortfolioGetOrCreate(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare('INSERT IGNORE INTO mbc_trade_portfolios (user_id) VALUES (?)');
    $stmt->execute([$userId]);

    $stmt = $pdo->prepare(
        'SELECT portfolios_id, user_id, cash, starting_cash, reset_count, last_reset_at,
                created_at, updated_at
         FROM mbc_trade_portfolios
         WHERE user_id = ?
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/** Hartes Trading-Gate: Lektion mit unlocks_feature='trading' abgeschlossen? */
function tradeTradingUnlocked(PDO $pdo, int $userId): bool {
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM mbc_trade_lessons l
         JOIN mbc_trade_lesson_progress p
           ON p.lesson_id = l.lessons_id AND p.user_id = ?
         WHERE p.status = \'completed\' AND l.unlocks_feature = \'trading\'
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    return (bool)$stmt->fetchColumn();
}

/** Letzter Sim-Tagesschlusskurs eines Wertpapiers (null bei source=live). */
function tradeSecurityLastClose(array $security, string $today): ?float {
    if (($security['source'] ?? 'sim') !== 'sim') {
        return null;
    }
    $series = tradeSimSeries(
        (int)$security['seed'],
        (string)$security['sim_start_date'],
        (float)$security['start_price'],
        (float)$security['drift'],
        (float)$security['volatility'],
        $today
    );
    $count = count($series);
    return $count > 0 ? $series[$count - 1]['close'] : null;
}

/**
 * Vollständiger Depot-Snapshot: Positionen mit aktueller Bewertung + Summen.
 * Antwort-Grundform für get-trade-portfolio, Order- und Reset-Handler.
 */
function tradePortfolioSnapshot(PDO $pdo, array $portfolio, int $userId): array {
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');

    $stmt = $pdo->prepare(
        'SELECT p.positions_id, p.security_id, p.quantity, p.avg_buy_price,
                s.symbol, s.name, s.sector, s.currency, s.source,
                s.start_price, s.drift, s.volatility, s.seed, s.sim_start_date
         FROM mbc_trade_positions p
         JOIN mbc_trade_securities s ON s.securities_id = p.security_id
         WHERE p.portfolio_id = ?
         ORDER BY s.symbol ASC'
    );
    $stmt->execute([(int)$portfolio['portfolios_id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $positions = [];
    $positionsValue = 0.0;
    foreach ($rows as $row) {
        $lastClose = tradeSecurityLastClose($row, $today);
        $quantity = (int)$row['quantity'];
        $avg = (float)$row['avg_buy_price'];
        $marketValue = $lastClose !== null ? round($quantity * $lastClose, 2) : 0.0;
        $costBasis = round($quantity * $avg, 2);
        $gainAbs = round($marketValue - $costBasis, 2);
        $gainPct = $costBasis > 0 ? round($gainAbs / $costBasis * 100, 2) : 0.0;
        $positionsValue += $marketValue;

        $positions[] = [
            'security_id'   => (int)$row['security_id'],
            'symbol'        => $row['symbol'],
            'name'          => $row['name'],
            'sector'        => $row['sector'],
            'currency'      => $row['currency'],
            'quantity'      => $quantity,
            'avg_buy_price' => $avg,
            'last_close'    => $lastClose,
            'market_value'  => $marketValue,
            'gain_abs'      => $gainAbs,
            'gain_pct'      => $gainPct,
        ];
    }

    $cash = (float)$portfolio['cash'];
    $startingCash = (float)$portfolio['starting_cash'];
    $portfolioValue = round($cash + $positionsValue, 2);
    $gainTotalAbs = round($portfolioValue - $startingCash, 2);
    $gainTotalPct = $startingCash > 0 ? round($gainTotalAbs / $startingCash * 100, 2) : 0.0;

    return [
        'portfolio_id'     => (int)$portfolio['portfolios_id'],
        'cash'             => round($cash, 2),
        'starting_cash'    => round($startingCash, 2),
        'reset_count'      => (int)$portfolio['reset_count'],
        'last_reset_at'    => $portfolio['last_reset_at'],
        'order_fee'        => TRADE_ORDER_FEE,
        'positions'        => $positions,
        'positions_value'  => round($positionsValue, 2),
        'portfolio_value'  => $portfolioValue,
        'gain_total_abs'   => $gainTotalAbs,
        'gain_total_pct'   => $gainTotalPct,
    ];
}
