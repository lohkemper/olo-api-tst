<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * DELETE /warehouse-packlists/{id} — Packliste löschen.
 * Positionen werden per FK CASCADE mitgelöscht; damit enden auch eventuelle
 * aktive Reservierungen dieser Packliste und der Bestand wird wieder verfügbar.
 *
 * @version 1.0.0
 */
class requestDeleteWarehousePacklists extends WarehousePacklistBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->getCurrentUser();
            if (!$user) {
                http_response_code(401);
                echo json_encode(['error' => 'Authentication required']);
                return;
            }
            $userId = (int)$user['users_id'];

            $packlistId = $this->requestId($this->request);
            if ($packlistId <= 0 || !$this->findPacklist($packlistId, $userId)) {
                http_response_code(404);
                echo json_encode(['error' => 'Packlist not found']);
                return;
            }

            $stmt = $this->pdo->prepare(
                "DELETE FROM " . PREFIX . "_warehouse_packlists
                 WHERE packlists_id = ? AND user_id = ?"
            );
            $stmt->execute([$packlistId, $userId]);

            http_response_code(200);
            echo json_encode(['success' => true, 'packlists_id' => $packlistId]);
        } catch (\Throwable $e) {
            $this->handleError('Error deleting packlist', $e);
        }
    }
}
