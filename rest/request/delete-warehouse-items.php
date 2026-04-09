<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * DELETE handler for warehouse-items endpoint
 * Handles: DELETE /api/warehouse-items/{id}
 *
 * @version 1.0.0
 */
class requestDeleteWarehouseItems extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            $this->log('requestDeleteWarehouseItems::execute');
            $this->log(['requestDeleteWarehouseItems::request', $this->request]);

            // Requires authentication
            $user = $this->getCurrentUser();
            if (!$user) {
                http_response_code(401);
                echo json_encode(['error' => 'Authentication required']);
                return;
            }

            $userId = (int)$user['users_id'];

            // ID is required
            if (!isset($this->request['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Item ID is required']);
                return;
            }

            $itemId = is_array($this->request['id'])
                ? (int)$this->request['id'][0]
                : (int)$this->request['id'];

            // Verify item exists and belongs to user
            $sql = "SELECT * FROM " . PREFIX . "_warehouse_items WHERE items_id = ? AND user_id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$itemId, $userId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                http_response_code(404);
                echo json_encode(['error' => 'Item not found']);
                return;
            }

            // Delete item (CASCADE will delete tag relations automatically)
            $sql = "DELETE FROM " . PREFIX . "_warehouse_items WHERE items_id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$itemId]);

            http_response_code(204); // No Content
        } catch (\Throwable $e) {
            $this->handleError('Error deleting warehouse item', $e);
        }
    }
}
