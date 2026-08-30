<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /trade/orders — Order-Historie des Users (neueste zuerst, max 100).
 */
class requestGetTradeOrders extends RequestBase {
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

            $stmt = $this->pdo->prepare(
                'SELECT o.orders_id, o.security_id, s.symbol, s.name,
                        o.side, o.quantity, o.price, o.fee, o.total, o.executed_at
                 FROM mbc_trade_orders o
                 JOIN mbc_trade_securities s ON s.securities_id = o.security_id
                 WHERE o.portfolio_id = ?
                 ORDER BY o.executed_at DESC, o.orders_id DESC
                 LIMIT 100'
            );
            $stmt->execute([(int)$portfolio['portfolios_id']]);

            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error fetching trade orders', $e);
        }
    }
}
