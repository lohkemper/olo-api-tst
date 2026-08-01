<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * DELETE /grow/preparations/{id} — Löscht ein Präparat (Cascade auf Schedule-Einträge).
 */
class requestDeleteGrowPreparations extends RequestBase {
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
                echo json_encode(['error' => 'Preparation id required']);
                return;
            }

            $stmt = $this->pdo->prepare('DELETE FROM mbc_grow_preparations WHERE grow_preparations_id = ? AND user_id = ?');
            $stmt->execute([$id, $userId]);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Preparation not found']);
                return;
            }

            http_response_code(204);
        } catch (\Throwable $e) {
            $this->handleError('Error deleting grow preparation', $e);
        }
    }
}
