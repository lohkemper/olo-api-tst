<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /trade/securities        — Liste aktiver Wertpapiere (Stammdaten)
 * GET /trade/securities/{id}   — einzelnes Wertpapier
 *
 * Die Simulationsparameter (seed, drift, volatility, start_price) werden
 * bewusst NICHT ausgegeben — der Lernbereich soll sich wie ein echter Markt
 * anfühlen; Kurse kommen ausschließlich über /prices bzw. /quotes.
 */
class requestGetTradeSecurities extends RequestBase {
    private array $request = [];

    private const COLS = 'securities_id, symbol, name, description, sector,
                          currency, source, is_active, created_at, updated_at';

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $this->requireAuth();

            $id = isset($this->request['id']) ? (int)$this->request['id'] : null;

            if ($id !== null) {
                $this->getById($id);
            } else {
                $this->getList();
            }
        } catch (\Throwable $e) {
            $this->handleError('Error fetching trade securities', $e);
        }
    }

    private function getList(): void {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLS . '
             FROM mbc_trade_securities
             WHERE is_active = 1
             ORDER BY symbol ASC'
        );
        $stmt->execute();
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function getById(int $id): void {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLS . '
             FROM mbc_trade_securities
             WHERE securities_id = ? AND is_active = 1
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Security not found']);
            return;
        }

        echo json_encode($row);
    }
}
