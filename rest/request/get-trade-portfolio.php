<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /trade/portfolio
 *
 * Depot des Users (get-or-create): Cash, Positionen mit aktueller Bewertung,
 * Summen (Depotwert, G/V ggü. Startkapital) + trading_unlocked-Flag fürs UI.
 */
class requestGetTradePortfolio extends RequestBase {
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
            $snapshot = tradePortfolioSnapshot($this->pdo, $portfolio, $userId);
            $snapshot['trading_unlocked'] = tradeTradingUnlocked($this->pdo, $userId);

            echo json_encode($snapshot);
        } catch (\Throwable $e) {
            $this->handleError('Error fetching trade portfolio', $e);
        }
    }
}
