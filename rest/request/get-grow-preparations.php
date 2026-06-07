<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /grow/preparations        — Düngepräparat-Katalog des Users
 * GET /grow/preparations/{id}   — Einzelnes Präparat
 */
class requestGetGrowPreparations extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');
            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $cols = 'grow_preparations_id, user_id, name, type, default_unit,
                     warehouse_item_id, meta, created_at, updated_at';

            if (isset($this->request['id'])) {
                $id = (int)$this->request['id'];
                $stmt = $this->pdo->prepare(
                    "SELECT $cols FROM mbc_grow_preparations WHERE grow_preparations_id = ? AND user_id = ? LIMIT 1"
                );
                $stmt->execute([$id, $userId]);
                $prep = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$prep) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Preparation not found']);
                    return;
                }
                echo json_encode($prep);
                return;
            }

            $stmt = $this->pdo->prepare(
                "SELECT $cols FROM mbc_grow_preparations WHERE user_id = ? ORDER BY name ASC"
            );
            $stmt->execute([$userId]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            $this->handleError('Error fetching grow preparations', $e);
        }
    }
}
