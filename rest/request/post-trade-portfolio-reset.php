<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /trade/portfolio/reset
 *
 * Setzt das Depot zurück: alle Positionen + Orders weg, Cash zurück auf das
 * Startkapital (10.000 EUR), reset_count++. Bewusst OHNE Trading-Gate —
 * ein Reset ist harmlos und Teil des Lernkonzepts (neu anfangen dürfen).
 * Antwort 200: frischer Depot-Snapshot.
 */
class requestPostTradePortfolioReset extends RequestBase {
    private array $data = [];

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $portfolio = tradePortfolioGetOrCreate($this->pdo, $userId);
            $portfolioId = (int)$portfolio['portfolios_id'];

            $this->pdo->beginTransaction();
            try {
                $stmt = $this->pdo->prepare('DELETE FROM mbc_trade_positions WHERE portfolio_id = ?');
                $stmt->execute([$portfolioId]);
                $stmt = $this->pdo->prepare('DELETE FROM mbc_trade_orders WHERE portfolio_id = ?');
                $stmt->execute([$portfolioId]);
                $stmt = $this->pdo->prepare(
                    'UPDATE mbc_trade_portfolios
                     SET cash = starting_cash,
                         reset_count = reset_count + 1,
                         last_reset_at = ?
                     WHERE portfolios_id = ?'
                );
                $stmt->execute([gmdate('Y-m-d H:i:s'), $portfolioId]);
                $this->pdo->commit();
            } catch (\Throwable $inner) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $inner;
            }

            $portfolio = tradePortfolioGetOrCreate($this->pdo, $userId);
            $snapshot = tradePortfolioSnapshot($this->pdo, $portfolio, $userId);
            $snapshot['trading_unlocked'] = tradeTradingUnlocked($this->pdo, $userId);

            echo json_encode($snapshot);
        } catch (\Throwable $e) {
            $this->handleError('Error resetting trade portfolio', $e);
        }
    }
}
