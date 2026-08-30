<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /trade/securities/{id}/prices?range=1m|3m|6m|1y|max
 *
 * Tageskurs-Zeitreihe als nacktes Array [{date, close}]. Kurse werden
 * deterministisch aus den Sim-Parametern berechnet (trade-simulation.php) —
 * lazy, ohne Cron, ohne Preis-Tabelle. source='live' ist der spätere
 * Hybrid-Pfad (mbc_trade_prices) und in V1 noch nicht implementiert.
 */
class requestGetTradeSecurityPrices extends RequestBase {
    private int $securityId = 0;
    private array $request = [];

    public function setSecurityId(int $securityId): void {
        $this->securityId = $securityId;
    }

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $this->requireAuth();

            $stmt = $this->pdo->prepare(
                'SELECT securities_id, source, start_price, drift, volatility,
                        seed, sim_start_date
                 FROM mbc_trade_securities
                 WHERE securities_id = ? AND is_active = 1
                 LIMIT 1'
            );
            $stmt->execute([$this->securityId]);
            $security = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$security) {
                http_response_code(404);
                echo json_encode(['error' => 'Security not found']);
                return;
            }

            if ($security['source'] !== 'sim') {
                http_response_code(501);
                echo json_encode(['error' => 'Live price source not implemented yet']);
                return;
            }

            $today = (new DateTimeImmutable('today'))->format('Y-m-d');
            $range = (string)($this->request['range'] ?? '3m');
            $from = tradeSimRangeFromDate($range, $today);

            $series = tradeSimSeries(
                (int)$security['seed'],
                (string)$security['sim_start_date'],
                (float)$security['start_price'],
                (float)$security['drift'],
                (float)$security['volatility'],
                $today
            );

            if ($from !== null) {
                $series = array_values(array_filter(
                    $series,
                    static fn(array $p): bool => $p['date'] >= $from
                ));
            }

            echo json_encode($series);
        } catch (\Throwable $e) {
            $this->handleError('Error computing trade security prices', $e);
        }
    }
}
