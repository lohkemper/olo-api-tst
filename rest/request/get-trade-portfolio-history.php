<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /trade/portfolio/history
 *
 * Depotwert-Verlauf als nacktes Array [{date, value, cash}] — je Handelstag
 * seit der ersten Order. Wird NICHT gespeichert, sondern exakt rekonstruiert:
 * Cash-Verlauf aus der Order-Historie (total ist vorzeichenbehaftet) und
 * Bestands-Bewertung aus der deterministischen Kursserie. Nach einem Reset
 * ist die Historie leer (Orders geloescht) — das ist gewollt.
 */
class requestGetTradePortfolioHistory extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $portfolio = tradePortfolioGetOrCreate($this->pdo, $userId);
            $portfolioId = (int)$portfolio['portfolios_id'];
            $startingCash = (float)$portfolio['starting_cash'];

            $stmt = $this->pdo->prepare(
                'SELECT security_id, side, quantity, total, DATE(executed_at) AS trade_date
                 FROM mbc_trade_orders
                 WHERE portfolio_id = ?
                 ORDER BY executed_at ASC, orders_id ASC'
            );
            $stmt->execute([$portfolioId]);
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($orders) === 0) {
                echo json_encode([]);
                return;
            }

            $today = (new DateTimeImmutable('today'))->format('Y-m-d');

            // Kursserien aller beteiligten Wertpapiere als date => close indexieren
            $securityIds = array_values(array_unique(array_map(
                static fn(array $o): int => (int)$o['security_id'],
                $orders
            )));
            $placeholders = implode(',', array_fill(0, count($securityIds), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT securities_id, source, start_price, drift, volatility, seed, sim_start_date
                 FROM mbc_trade_securities
                 WHERE securities_id IN ($placeholders)"
            );
            $stmt->execute($securityIds);

            $closesBySecurity = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $security) {
                $series = tradeSimSeries(
                    (int)$security['seed'],
                    (string)$security['sim_start_date'],
                    (float)$security['start_price'],
                    (float)$security['drift'],
                    (float)$security['volatility'],
                    $today
                );
                $byDate = [];
                foreach ($series as $point) {
                    $byDate[$point['date']] = $point['close'];
                }
                $closesBySecurity[(int)$security['securities_id']] = $byDate;
            }

            // Handelstage (Werktage) von der ersten Order bis heute durchlaufen;
            // Orders wirken ab ihrem Ausfuehrungstag (inklusive).
            $day = new DateTimeImmutable((string)$orders[0]['trade_date']);
            $end = new DateTimeImmutable($today);
            $orderIndex = 0;
            $orderCount = count($orders);
            $cash = $startingCash;
            $holdings = []; // security_id => qty

            $history = [];
            while ($day <= $end) {
                $date = $day->format('Y-m-d');
                $weekday = (int)$day->format('N');

                while ($orderIndex < $orderCount && (string)$orders[$orderIndex]['trade_date'] <= $date) {
                    $order = $orders[$orderIndex];
                    $secId = (int)$order['security_id'];
                    $qty = (int)$order['quantity'];
                    $cash += (float)$order['total'];
                    $holdings[$secId] = ($holdings[$secId] ?? 0) + ($order['side'] === 'buy' ? $qty : -$qty);
                    $orderIndex++;
                }

                if ($weekday <= 5) {
                    $positionsValue = 0.0;
                    foreach ($holdings as $secId => $qty) {
                        if ($qty <= 0) continue;
                        $close = $closesBySecurity[$secId][$date] ?? null;
                        if ($close !== null) {
                            $positionsValue += $qty * $close;
                        }
                    }
                    $history[] = [
                        'date'  => $date,
                        'value' => round($cash + $positionsValue, 2),
                        'cash'  => round($cash, 2),
                    ];
                }

                $day = $day->modify('+1 day');
            }

            echo json_encode($history);
        } catch (\Throwable $e) {
            $this->handleError('Error computing trade portfolio history', $e);
        }
    }
}
