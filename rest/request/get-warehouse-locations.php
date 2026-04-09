<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET handler for warehouse-locations endpoint
 * Handles:
 * - GET /api/warehouse-locations (all locations for user)
 * - GET /api/warehouse-locations/{id} (specific location)
 * - GET /api/warehouse-locations/{id}/tree (subtree from location)
 * - GET /api/warehouse-locations/tree (complete tree)
 * - GET /api/warehouse-locations/{id}/items (items at location)
 *
 * @version 1.0.0
 */
class requestGetWarehouseLocations extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            $this->log('requestGetWarehouseLocations::execute');
            $this->log(['requestGetWarehouseLocations::request', $this->request]);

            // Warehouse requires authentication
            $user = $this->getCurrentUser();
            if (!$user) {
                http_response_code(401);
                echo json_encode(['error' => 'Authentication required']);
                return;
            }

            $userId = (int)$user['users_id'];

            // Route to appropriate handler
            if (isset($this->request['id'])) {
                $locationId = is_array($this->request['id'])
                    ? (int)$this->request['id'][0]
                    : (int)$this->request['id'];

                // Check for sub-routes
                if (isset($this->request['subroute'])) {
                    $subroute = $this->request['subroute'];

                    if ($subroute === 'tree') {
                        $this->handleGetSubtree($userId, $locationId);
                    } elseif ($subroute === 'items') {
                        $this->handleGetItemsByLocation($userId, $locationId);
                    } else {
                        http_response_code(404);
                        echo json_encode(['error' => 'Unknown subroute']);
                    }
                } else {
                    // Single location
                    $this->handleGetLocation($userId, $locationId);
                }
            } elseif (isset($this->request['subroute']) && $this->request['subroute'] === 'tree') {
                // Complete tree
                $this->handleGetCompleteTree($userId);
            } else {
                // All locations (with optional filters)
                $this->handleGetLocations($userId);
            }

        } catch (\Throwable $e) {
            $this->handleError('Error executing GET warehouse-locations request', $e);
        }
    }

    /**
     * GET /api/warehouse-locations - All locations for user
     */
    private function handleGetLocations(int $userId): void {
        $sql = "
            SELECT
                locations_id,
                name,
                parent_id,
                path,
                level,
                type,
                description,
                meta,
                user_id,
                created_at,
                updated_at
            FROM " . PREFIX . "_warehouse_locations
            WHERE user_id = ?
        ";

        $params = [$userId];

        // Filter by parent_id if provided
        if (isset($this->request['parent_id'])) {
            if ($this->request['parent_id'] === 'null' || $this->request['parent_id'] === '') {
                $sql .= " AND parent_id IS NULL";
            } else {
                $sql .= " AND parent_id = ?";
                $params[] = (int)$this->request['parent_id'];
            }
        }

        // Filter by type if provided
        if (isset($this->request['type'])) {
            $sql .= " AND type = ?";
            $params[] = $this->request['type'];
        }

        $sql .= " ORDER BY level ASC, name ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($locations);
    }

    /**
     * GET /api/warehouse-locations/{id} - Single location
     */
    private function handleGetLocation(int $userId, int $locationId): void {
        $sql = "
            SELECT
                locations_id,
                name,
                parent_id,
                path,
                level,
                type,
                description,
                meta,
                user_id,
                created_at,
                updated_at
            FROM " . PREFIX . "_warehouse_locations
            WHERE locations_id = ? AND user_id = ?
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$locationId, $userId]);
        $location = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$location) {
            http_response_code(404);
            echo json_encode(['error' => 'Location not found']);
            return;
        }

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([$location]);
    }

    /**
     * GET /api/warehouse-locations/{id}/tree - Subtree from location
     */
    private function handleGetSubtree(int $userId, int $locationId): void {
        // First verify location exists and belongs to user
        $sql = "
            SELECT locations_id, path
            FROM " . PREFIX . "_warehouse_locations
            WHERE locations_id = ? AND user_id = ?
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$locationId, $userId]);
        $root = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$root) {
            http_response_code(404);
            echo json_encode(['error' => 'Location not found']);
            return;
        }

        // Get all descendants (using path LIKE pattern)
        $sql = "
            SELECT
                locations_id,
                name,
                parent_id,
                path,
                level,
                type,
                description,
                meta,
                user_id,
                created_at,
                updated_at
            FROM " . PREFIX . "_warehouse_locations
            WHERE user_id = ?
              AND (locations_id = ? OR path LIKE ?)
            ORDER BY level ASC, name ASC
        ";

        $pathPattern = $root['path'] . '/%';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId, $locationId, $pathPattern]);
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($locations);
    }

    /**
     * GET /api/warehouse-locations/tree - Complete tree for user
     */
    private function handleGetCompleteTree(int $userId): void {
        $sql = "
            SELECT
                locations_id,
                name,
                parent_id,
                path,
                level,
                type,
                description,
                meta,
                user_id,
                created_at,
                updated_at
            FROM " . PREFIX . "_warehouse_locations
            WHERE user_id = ?
            ORDER BY level ASC, name ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId]);
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($locations);
    }

    /**
     * GET /api/warehouse-locations/{id}/items - Items at location
     */
    private function handleGetItemsByLocation(int $userId, int $locationId): void {
        // Verify location exists and belongs to user
        $sql = "
            SELECT locations_id
            FROM " . PREFIX . "_warehouse_locations
            WHERE locations_id = ? AND user_id = ?
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$locationId, $userId]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Location not found']);
            return;
        }

        // Check if include_children parameter is set
        $includeChildren = isset($this->request['include_children'])
            && $this->request['include_children'] === 'true';

        if ($includeChildren) {
            // Get location path for subtree query
            $sql = "SELECT path FROM " . PREFIX . "_warehouse_locations WHERE locations_id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$locationId]);
            $location = $stmt->fetch(PDO::FETCH_ASSOC);

            // Get items in this location and all descendants
            $sql = "
                SELECT i.*
                FROM " . PREFIX . "_warehouse_items i
                INNER JOIN " . PREFIX . "_warehouse_locations l ON i.location_id = l.locations_id
                WHERE i.user_id = ?
                  AND (l.locations_id = ? OR l.path LIKE ?)
                ORDER BY i.name ASC
            ";

            $pathPattern = $location['path'] . '/%';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$userId, $locationId, $pathPattern]);
        } else {
            // Get items only at this location
            $sql = "
                SELECT *
                FROM " . PREFIX . "_warehouse_items
                WHERE location_id = ? AND user_id = ?
                ORDER BY name ASC
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$locationId, $userId]);
        }

        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Enrich items with tags
        $enrichedItems = array_map(function($item) {
            return $this->enrichItemWithTags($item);
        }, $items);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($enrichedItems);
    }

    /**
     * Enriches item with tags from junction table
     */
    private function enrichItemWithTags(array $item): array {
        $itemId = (int)$item['items_id'];

        $sql = "
            SELECT t.tags_id AS id, t.name
            FROM " . PREFIX . "_tags t
            INNER JOIN " . PREFIX . "_warehouse_item_tags it ON t.tags_id = it.tag_id
            WHERE it.items_id = ?
            ORDER BY t.name
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$itemId]);
        $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $item['tags'] = $tags;
        return $item;
    }
}
