<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /trade/orders — Body: {"security_id":1,"side":"buy"|"sell","quantity":5}
 *
 * Papertrading-Order: Ausführung SOFORT zum letzten Sim-Tagesschlusskurs,
 * 1 EUR Flat-Gebühr, GANZE Stücke. Validierung komplett serverseitig:
 *  - hartes Gate: Lektion 2 (unlocks_feature='trading') muss abgeschlossen sein → sonst 403
 *  - buy: Kosten (qty*price+fee) <= Cash
 *  - sell: qty <= Bestand; Erlös (qty*price-fee) muss > 0 sein
 * Antwort 201: Depot-Snapshot + 'order'.
 */
class requestPostTradeOrders extends RequestBase {
    private array $data = [];

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            if (!tradeTradingUnlocked($this->pdo, $userId)) {
                http_response_code(403);
                echo json_encode(['error' => 'Trading locked', 'message' => 'Complete lesson 2 to unlock trading']);
                return;
            }

            $securityId = (int)($this->data['security_id'] ?? 0);
            $side = (string)($this->data['side'] ?? '');
            $quantity = (int)($this->data['quantity'] ?? 0);

            if ($securityId <= 0 || !in_array($side, ['buy', 'sell'], true)
                || $quantity < 1 || $quantity > 1000000) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid payload', 'message' => 'security_id, side (buy|sell) and quantity (>=1) expected']);
                return;
            }

            $stmt = $this->pdo->prepare(
                'SELECT securities_id, symbol, name, source,
                        start_price, drift, volatility, seed, sim_start_date
                 FROM mbc_trade_securities
                 WHERE securities_id = ? AND is_active = 1
                 LIMIT 1'
            );
            $stmt->execute([$securityId]);
            $security = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$security) {
                http_response_code(404);
                echo json_encode(['error' => 'Security not found']);
                return;
            }

            $today = (new DateTimeImmutable('today'))->format('Y-m-d');
            $price = tradeSecurityLastClose($security, $today);
            if ($price === null) {
                http_response_code(501);
                echo json_encode(['error' => 'No price available for this security']);
                return;
            }

            $portfolio = tradePortfolioGetOrCreate($this->pdo, $userId);
            $portfolioId = (int)$portfolio['portfolios_id'];
            $fee = TRADE_ORDER_FEE;

            $this->pdo->beginTransaction();
            try {
                // Depot-Zeile sperren (paralleles Ordern desselben Users serialisieren)
                $stmt = $this->pdo->prepare(
                    'SELECT cash FROM mbc_trade_portfolios WHERE portfolios_id = ? FOR UPDATE'
                );
                $stmt->execute([$portfolioId]);
                $cash = (float)$stmt->fetchColumn();

                $stmt = $this->pdo->prepare(
                    'SELECT positions_id, quantity, avg_buy_price
                     FROM mbc_trade_positions
                     WHERE portfolio_id = ? AND security_id = ?
                     FOR UPDATE'
                );
                $stmt->execute([$portfolioId, $securityId]);
                $position = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($side === 'buy') {
                    $cost = round($quantity * $price + $fee, 2);
                    if ($cost > $cash) {
                        $this->pdo->rollBack();
                        http_response_code(400);
                        echo json_encode(['error' => 'Insufficient funds', 'message' => 'Order costs exceed available cash']);
                        return;
                    }

                    if ($position) {
                        $oldQty = (int)$position['quantity'];
                        $oldAvg = (float)$position['avg_buy_price'];
                        $newQty = $oldQty + $quantity;
                        $newAvg = round(($oldQty * $oldAvg + $quantity * $price) / $newQty, 4);
                        $stmt = $this->pdo->prepare(
                            'UPDATE mbc_trade_positions SET quantity = ?, avg_buy_price = ?
                             WHERE positions_id = ?'
                        );
                        $stmt->execute([$newQty, $newAvg, (int)$position['positions_id']]);
                    } else {
                        $stmt = $this->pdo->prepare(
                            'INSERT INTO mbc_trade_positions (portfolio_id, security_id, quantity, avg_buy_price)
                             VALUES (?, ?, ?, ?)'
                        );
                        $stmt->execute([$portfolioId, $securityId, $quantity, round($price, 4)]);
                    }

                    $total = -$cost;
                } else {
                    $held = $position ? (int)$position['quantity'] : 0;
                    if ($quantity > $held) {
                        $this->pdo->rollBack();
                        http_response_code(400);
                        echo json_encode(['error' => 'Insufficient holdings', 'message' => 'You cannot sell more than you own']);
                        return;
                    }

                    $proceeds = round($quantity * $price - $fee, 2);
                    if ($proceeds <= 0) {
                        $this->pdo->rollBack();
                        http_response_code(400);
                        echo json_encode(['error' => 'Order value below fee', 'message' => 'Order value must exceed the 1 EUR fee']);
                        return;
                    }

                    $remaining = $held - $quantity;
                    if ($remaining === 0) {
                        $stmt = $this->pdo->prepare('DELETE FROM mbc_trade_positions WHERE positions_id = ?');
                        $stmt->execute([(int)$position['positions_id']]);
                    } else {
                        $stmt = $this->pdo->prepare(
                            'UPDATE mbc_trade_positions SET quantity = ? WHERE positions_id = ?'
                        );
                        $stmt->execute([$remaining, (int)$position['positions_id']]);
                    }

                    $total = $proceeds;
                }

                $newCash = round($cash + $total, 2);
                $stmt = $this->pdo->prepare(
                    'UPDATE mbc_trade_portfolios SET cash = ? WHERE portfolios_id = ?'
                );
                $stmt->execute([$newCash, $portfolioId]);

                $executedAt = gmdate('Y-m-d H:i:s');
                $stmt = $this->pdo->prepare(
                    'INSERT INTO mbc_trade_orders
                       (portfolio_id, security_id, side, quantity, price, fee, total, executed_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $portfolioId, $securityId, $side, $quantity,
                    round($price, 4), $fee, $total, $executedAt,
                ]);
                $orderId = (int)$this->pdo->lastInsertId();

                $this->pdo->commit();
            } catch (\Throwable $inner) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $inner;
            }

            $portfolio = tradePortfolioGetOrCreate($this->pdo, $userId);
            $snapshot = tradePortfolioSnapshot($this->pdo, $portfolio, $userId);
            $snapshot['trading_unlocked'] = true;
            $snapshot['order'] = [
                'orders_id'   => $orderId,
                'security_id' => $securityId,
                'symbol'      => $security['symbol'],
                'name'        => $security['name'],
                'side'        => $side,
                'quantity'    => $quantity,
                'price'       => round($price, 4),
                'fee'         => $fee,
                'total'       => $total,
                'executed_at' => $executedAt,
            ];

            http_response_code(201);
            echo json_encode($snapshot);
        } catch (\Throwable $e) {
            $this->handleError('Error placing trade order', $e);
        }
    }
}
