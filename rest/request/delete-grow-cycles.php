<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * DELETE /grow/cycles/{id} — Löscht einen Durchlauf inkl. Pflanzen (Cascade).
 */
class requestDeleteGrowCycles extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $cycleId = isset($this->request['id']) ? (int)$this->request['id'] : 0;
            if ($cycleId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Cycle id required']);
                return;
            }

            $stmt = $this->pdo->prepare(
                'DELETE FROM mbc_grow_cycles WHERE grow_cycles_id = ? AND user_id = ?'
            );
            $stmt->execute([$cycleId, $userId]);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Cycle not found']);
                return;
            }

            http_response_code(204);
        } catch (\Throwable $e) {
            $this->handleError('Error deleting grow cycle', $e);
        }
    }
}
