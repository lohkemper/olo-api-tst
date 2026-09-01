<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Specialized GET handler for navigations endpoint
 * Handles: GET /api/navigations, GET /api/navigations/{id}
 *
 * Permission-based filtering:
 * - If navigation has permission_id set, user must have that permission (directly or via role)
 * - If navigation has no permission_id, it's visible to all authenticated users
 */
class requestGetNavigations extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            $this->log('requestGetNavigations::execute');
            $this->log(['requestGetNavigations::request', $this->request]);

            // Require authentication to access navigations
            $user = $this->requireAuth();

            // Check if specific navigation ID is requested
            if (isset($this->request['id'])) {
                $id = is_array($this->request['id']) ? $this->request['id'] : [$this->request['id']];
                $this->handleGetFilteredNavigations($user, $id);
            } else {
                $this->handleGetFilteredNavigations($user);
            }

        } catch (\Throwable $e) {
            $this->handleError('Error executing GET navigations request', $e);
        }
    }

    /**
     * GET /api/navigations or GET /api/navigations/{id}
     * Returns navigations filtered by user permissions
     */
    private function handleGetFilteredNavigations(array $user, ?array $ids = null): void {
        // Build base query with permission join
        $sql = "
            SELECT
                n.navigations_id,
                n.parent_id,
                n.title,
                n.description,
                n.route,
                n.icon,
                n.permission_id,
                n.sort_order,
                n.is_active,
                n.target,
                n.css_class,
                n.badge_text,
                n.badge_class,
                n.created_at,
                n.updated_at
            FROM " . PREFIX . "_navigations n
        ";

        $params = [];

        // Filter by specific IDs if provided
        if ($ids !== null) {
            $placeholders = array_fill(0, count($ids), '?');
            $sql .= " WHERE n.navigations_id IN (" . implode(',', $placeholders) . ")";
            $params = array_map('intval', $ids);
        }

        $sql .= " ORDER BY n.parent_id, n.sort_order, n.title";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $navigations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Filter navigations based on user permissions
        $filteredNavigations = array_filter($navigations, function($nav) use ($user) {
            return $this->userCanSeeNavigation($user, $nav);
        });

        // Format navigations
        $formattedNavigations = array_map(function($nav) {
            return [
                'navigations_id' => (int)$nav['navigations_id'],
                'parent_id' => $nav['parent_id'] !== null ? (int)$nav['parent_id'] : null,
                'title' => $nav['title'],
                'description' => $nav['description'],
                'route' => $nav['route'],
                'icon' => $nav['icon'],
                'permission_id' => $nav['permission_id'] !== null ? (int)$nav['permission_id'] : null,
                'sort_order' => (int)$nav['sort_order'],
                'is_active' => (bool)$nav['is_active'],
                'target' => $nav['target'],
                'css_class' => $nav['css_class'],
                'badge_text' => $nav['badge_text'],
                'badge_class' => $nav['badge_class'],
                'created_at' => $nav['created_at'],
                'updated_at' => $nav['updated_at']
            ];
        }, array_values($filteredNavigations)); // array_values to reindex after filter

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($formattedNavigations);
    }

    /**
     * Check if user can see a navigation item
     * User can see navigation if:
     * 1. Navigation has no permission_id (public for authenticated users)
     * 2. User has the required permission (directly or via role)
     */
    private function userCanSeeNavigation(array $user, array $navigation): bool {
        // If no permission required, navigation is visible to all authenticated users
        if ($navigation['permission_id'] === null) {
            return true;
        }

        $requiredPermissionId = (int)$navigation['permission_id'];

        // Check if user has this permission directly
        if (isset($user['permissions']) && is_array($user['permissions'])) {
            foreach ($user['permissions'] as $permission) {
                if ((int)$permission['permissions_id'] === $requiredPermissionId) {
                    return true;
                }
            }
        }

        // Check if user has this permission via roles
        if (isset($user['roles']) && is_array($user['roles'])) {
            foreach ($user['roles'] as $role) {
                if (isset($role['permissions']) && is_array($role['permissions'])) {
                    foreach ($role['permissions'] as $permission) {
                        if ((int)$permission['permissions_id'] === $requiredPermissionId) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }
}
