<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /trade/quotes
 *
 * Batch-Endpoint für die Markt-Liste: pro aktivem Wertpapier der letzte
 * Tagesschlusskurs, das Delta zum Vortag und eine Sparkline (letzte 30
 * Schlusskurse) — EIN Request für die gesamte Liste.
 */
class requestGetTradeQuotes extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $this->requireAuth();

            $stmt = $this->pdo->prepare(
                'SELECT securities_id, symbol, name, sector, currency, source,
                        start_price, drift, volatility, seed, sim_start_date
                 FROM mbc_trade_securities
                 WHERE is_active = 1
                 ORDER BY symbol ASC'
            );
            $stmt->execute();
            $securities = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $today = (new DateTimeImmutable('today'))->format('Y-m-d');
            $quotes = [];

            foreach ($securities as $security) {
                if ($security['source'] !== 'sim') {
                    continue; // Live-Quellen erst mit mbc_trade_prices (V2+)
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
                if ($count === 0) {
                    continue;
                }

                $last = $series[$count - 1]['close'];
                $prev = $count > 1 ? $series[$count - 2]['close'] : $last;
                $changeAbs = round($last - $prev, 4);
                $changePct = $prev > 0 ? round(($last - $prev) / $prev * 100, 2) : 0.0;

                $spark = array_map(
                    static fn(array $p): float => $p['close'],
                    array_slice($series, -30)
                );

                $quotes[] = [
                    'security_id' => (int)$security['securities_id'],
                    'symbol'      => $security['symbol'],
                    'name'        => $security['name'],
                    'sector'      => $security['sector'],
                    'currency'    => $security['currency'],
                    'last_close'  => $last,
                    'prev_close'  => $prev,
                    'change_abs'  => $changeAbs,
                    'change_pct'  => $changePct,
                    'spark'       => $spark,
                ];
            }

            echo json_encode($quotes);
        } catch (\Throwable $e) {
            $this->handleError('Error computing trade quotes', $e);
        }
    }
}
