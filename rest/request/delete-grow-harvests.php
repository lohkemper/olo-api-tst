<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * DELETE /grow/harvests/{id} — Löscht einen Ernte-Eintrag.
 * Ownership über Join auf die Pflanze (plant.user_id).
 */
class requestDeleteGrowHarvests extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');
            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $id = isset($this->request['id']) ? (int)$this->request['id'] : 0;
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Harvest id required']);
                return;
            }

            $stmt = $this->pdo->prepare(
                'DELETE h FROM mbc_grow_harvests h
                 INNER JOIN mbc_grow_plants p ON p.grow_plants_id = h.plant_id
                 WHERE h.grow_harvests_id = ? AND p.user_id = ?'
            );
            $stmt->execute([$id, $userId]);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Harvest not found']);
                return;
            }

            http_response_code(204);
        } catch (\Throwable $e) {
            $this->handleError('Error deleting harvest', $e);
        }
    }
}
