<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * DELETE /grow/plants/{id} — Löscht eine Pflanze (Cascade auf Feeding/Messungen/Ernte).
 */
class requestDeleteGrowPlants extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $plantId = isset($this->request['id']) ? (int)$this->request['id'] : 0;
            if ($plantId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Plant id required']);
                return;
            }

            $stmt = $this->pdo->prepare(
                'DELETE FROM mbc_grow_plants WHERE grow_plants_id = ? AND user_id = ?'
            );
            $stmt->execute([$plantId, $userId]);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Plant not found']);
                return;
            }

            http_response_code(204);
        } catch (\Throwable $e) {
            $this->handleError('Error deleting grow plant', $e);
        }
    }
}
