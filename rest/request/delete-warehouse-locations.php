<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * DELETE handler for warehouse-locations endpoint
 * Handles: DELETE /api/warehouse-locations/{id}
 *
 * @version 1.0.0
 */
class requestDeleteWarehouseLocations extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            $this->log('requestDeleteWarehouseLocations::execute');
            $this->log(['requestDeleteWarehouseLocations::request', $this->request]);

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
                echo json_encode(['error' => 'Location ID is required']);
                return;
            }

            $locationId = is_array($this->request['id'])
                ? (int)$this->request['id'][0]
                : (int)$this->request['id'];

            // Verify location exists and belongs to user
            $sql = "SELECT * FROM " . PREFIX . "_warehouse_locations WHERE locations_id = ? AND user_id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$locationId, $userId]);
            $location = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$location) {
                http_response_code(404);
                echo json_encode(['error' => 'Location not found']);
                return;
            }

            // Delete location (CASCADE will delete children automatically)
            // Items will be set to location_id = NULL (SET NULL constraint)
            $sql = "DELETE FROM " . PREFIX . "_warehouse_locations WHERE locations_id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$locationId]);

            http_response_code(204); // No Content
        } catch (\Throwable $e) {
            $this->handleError('Error deleting warehouse location', $e);
        }
    }
}
