<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Specialized GET handler for navigation_roles endpoint
 * Handles: GET /api/navigation_roles?navigation_id={id}
 *          GET /api/navigation_roles?role_id={id}
 */
class requestGetNavigationRoles extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            $this->log('requestGetNavigationRoles::execute');
            $this->log(['requestGetNavigationRoles::request', $this->request]);

            // Require authentication
            $user = $this->requireAnyPermission(['navigation.read', 'admin.access']);

            // Check which filter is requested
            if (isset($this->request['navigation_id'])) {
                $this->handleGetByNavigationId((int)$this->request['navigation_id']);
            } elseif (isset($this->request['role_id'])) {
                $this->handleGetByRoleId((int)$this->request['role_id']);
            } else {
                $this->handleGetAll();
            }

        } catch (\Throwable $e) {
            $this->handleError('Error executing GET navigation_roles request', $e);
        }
    }

    /**
     * GET /api/navigation_roles?navigation_id={id}
     * Returns all roles assigned to a specific navigation
     */
    private function handleGetByNavigationId(int $navigationId): void {
        $stmt = $this->pdo->prepare("
            SELECT
                nr.navigations_id AS navigation_id,
                nr.role_id,
                nr.assigned_at,
                nr.assigned_by,
                r.name AS role_name,
                r.display_name AS role_display_name
            FROM " . PREFIX . "_navigation_roles nr
            INNER JOIN " . PREFIX . "_roles r ON nr.role_id = r.roles_id
            WHERE nr.navigations_id = :navigationId
            ORDER BY r.display_name
        ");

        $stmt->execute(['navigationId' => $navigationId]);
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format response
        $formattedRoles = array_map(function($role) {
            return [
                'navigation_id' => (int)$role['navigation_id'],
                'role_id' => (int)$role['role_id'],
                'assigned_at' => $role['assigned_at'],
                'assigned_by' => $role['assigned_by'] ? (int)$role['assigned_by'] : null,
                'role_name' => $role['role_name'],
                'role_display_name' => $role['role_display_name']
            ];
        }, $roles);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($formattedRoles);
    }

    /**
     * GET /api/navigation_roles?role_id={id}
     * Returns all navigations assigned to a specific role
     */
    private function handleGetByRoleId(int $roleId): void {
        $stmt = $this->pdo->prepare("
            SELECT
                nr.navigations_id AS navigation_id,
                nr.role_id,
                nr.assigned_at,
                nr.assigned_by,
                n.title AS navigation_title
            FROM " . PREFIX . "_navigation_roles nr
            INNER JOIN " . PREFIX . "_navigations n ON nr.navigations_id = n.navigations_id
            WHERE nr.role_id = :roleId
            ORDER BY n.title
        ");

        $stmt->execute(['roleId' => $roleId]);
        $navigations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format response
        $formattedNavigations = array_map(function($nav) {
            return [
                'navigation_id' => (int)$nav['navigation_id'],
                'role_id' => (int)$nav['role_id'],
                'assigned_at' => $nav['assigned_at'],
                'assigned_by' => $nav['assigned_by'] ? (int)$nav['assigned_by'] : null,
                'navigation_title' => $nav['navigation_title']
            ];
        }, $navigations);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($formattedNavigations);
    }

    /**
     * GET /api/navigation_roles
     * Returns all navigation-role assignments
     */
    private function handleGetAll(): void {
        $stmt = $this->pdo->prepare("
            SELECT
                nr.navigations_id AS navigation_id,
                nr.role_id,
                nr.assigned_at,
                nr.assigned_by
            FROM " . PREFIX . "_navigation_roles nr
            ORDER BY nr.navigations_id, nr.role_id
        ");

        $stmt->execute();
        $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format response
        $formattedAssignments = array_map(function($assignment) {
            return [
                'navigation_id' => (int)$assignment['navigation_id'],
                'role_id' => (int)$assignment['role_id'],
                'assigned_at' => $assignment['assigned_at'],
                'assigned_by' => $assignment['assigned_by'] ? (int)$assignment['assigned_by'] : null
            ];
        }, $assignments);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($formattedAssignments);
    }
}
