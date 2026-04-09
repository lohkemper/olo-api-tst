<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Specialized GET handler for auth-related endpoints
 * Handles: GET /api/auth/user, GET /api/auth/csrf-token
 */
class requestGetAuth extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            $this->log('requestGetAuth::execute');
            $this->log(['requestGetAuth::request', $this->request]);

            // Determine which auth endpoint is being called
            $subRoute = $this->request['subroute'] ?? '';

            switch ($subRoute) {
                case 'user':
                    $this->handleGetUser();
                    break;

                case 'csrf-token':
                    $this->handleGetCsrfToken();
                    break;

                default:
                    http_response_code(404);
                    echo json_encode(['error' => 'Auth endpoint not found', 'subroute' => $subRoute]);
                    break;
            }

        } catch (\Throwable $e) {
            error_log('GET /auth error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());

            // Always output detailed error for debugging (remove in production)
            http_response_code(500);
            echo json_encode([
                'error' => 'Error executing GET auth request',
                'details' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], JSON_PRETTY_PRINT);
            exit;
        }
    }

    /**
     * GET /api/auth/user
     * Returns current authenticated user with roles and permissions
     */
    private function handleGetUser(): void {
        // Require authentication
        $user = $this->requireAuth();

        // User already includes roles and permissions from AuthHelper
        // Format response to match API contract
        $response = [
            'user' => [
                'users_id' => (int)$user['users_id'],
                'email' => $user['email'],
                'username' => $user['username'],
                'first_name' => $user['first_name'] ?? '',
                'last_name' => $user['last_name'] ?? '',
                'bio' => '', // Not in database yet
                'image' => '', // Not in database yet
                'roles' => $this->formatRoles($user['roles'] ?? []),
                'permissions' => $this->formatPermissions($user['permissions'] ?? []),
                'is_active' => (bool)($user['is_active'] ?? true),
                'isBanned' => false
            ]
        ];

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($response);
    }

    /**
     * GET /api/auth/csrf-token
     * Returns a new CSRF token for the current session
     */
    private function handleGetCsrfToken(): void {
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Generate CSRF token
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        $response = [
            'csrfToken' => $_SESSION['csrf_token']
        ];

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($response);
    }

    /**
     * Format roles for API response
     */
    private function formatRoles(array $roles): array {
        return array_map(function($role) {
            return [
                'roles_id' => (int)$role['roles_id'],
                'name' => $role['name'],
                'display_name' => $role['display_name'] ?? '',
                'description' => $role['description'] ?? '',
                'permissions' => $this->formatPermissions($role['permissions'] ?? [])
            ];
        }, $roles);
    }

    /**
     * Format permissions for API response
     */
    private function formatPermissions(array $permissions): array {
        return array_map(function($permission) {
            return [
                'permissions_id' => (int)$permission['permissions_id'],
                'name' => $permission['name'],
                'resource' => $permission['resource'],
                'action' => $permission['action'],
                'scope' => $permission['scope'] ?? null,
                'description' => $permission['description'] ?? ''
            ];
        }, $permissions);
    }
}
