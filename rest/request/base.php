<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

require_once __DIR__ . '/../auth/auth-helper.php';

/**
 * Base class for all request handlers
 * Provides common functionality like logging, PDO access, and error handling
 */
abstract class RequestBase {
    protected array $logs = [];
    protected string $area = '';
    protected PDO $pdo;
    protected ?AuthHelper $authHelper = null;

    public function __construct(PDO $pdo, string $area) {
        $this->pdo = $pdo;
        $this->area = $area;
        $this->authHelper = new AuthHelper($pdo);
    }

    /**
     * Log a message with optional type
     */
    protected function log(mixed $msg, string $type = 'info'): void {
        array_push($this->logs, [$type, $msg]);
    }

    /**
     * Get the full table name with prefix
     */
    protected function getArea(): string {
        return $this->area;
    }

    /**
     * Display debug information if debug mode is enabled
     */
    protected function debugOutput(): void {
        global $debug;
        if ($debug) {
            echo '<pre>' . print_r($this->logs, true) . '</pre>';
        }
    }

    /**
     * Handle errors with proper logging and response
     */
    protected function handleError(string $message, \Throwable $e = null, int $statusCode = 500): void {
        $this->log(['error' => $message, 'exception' => $e?->getMessage()], 'error');

        http_response_code($statusCode);

        if (DEBUG) {
            echo json_encode([
                'error' => $message,
                'details' => $e?->getMessage(),
                'trace' => $e?->getTraceAsString(),
                'file' => $e?->getFile(),
                'line' => $e?->getLine()
            ], JSON_PRETTY_PRINT);
        } else {
            echo json_encode(['error' => $message]);
        }

        exit;
    }

    /**
     * Validate column name to prevent SQL injection
     */
    protected function isValidColumnName(string $name): bool {
        return (bool)preg_match('/^[a-zA-Z0-9_]+$/', $name);
    }

    /**
     * Execute the request - must be implemented by child classes
     */
    abstract public function execute(): void;

    // =====================================================================
    // Authentication & Authorization Methods
    // =====================================================================

    /**
     * Get current authenticated user
     */
    protected function getCurrentUser(): ?array {
        return $this->authHelper->getCurrentUser();
    }

    /**
     * Check if user is authenticated
     */
    protected function isAuthenticated(): bool {
        return $this->authHelper->isAuthenticated();
    }

    /**
     * Require authentication - sends 401 if not authenticated
     */
    protected function requireAuth(): array {
        return $this->authHelper->requireAuth();
    }

    /**
     * Check if user has a specific role
     */
    protected function hasRole(array $user, string $roleName): bool {
        foreach ($user['roles'] as $role) {
            if ($role['name'] === $roleName) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if user has any of the specified roles
     */
    protected function hasAnyRole(array $user, array $roleNames): bool {
        foreach ($roleNames as $roleName) {
            if ($this->hasRole($user, $roleName)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Require user to have specific role - sends 403 if not authorized
     */
    protected function requireRole(string $roleName): array {
        $user = $this->requireAuth();

        if (!$this->hasRole($user, $roleName)) {
            http_response_code(403);
            echo json_encode([
                'error' => 'Forbidden',
                'message' => "Required role: {$roleName}"
            ]);
            exit;
        }

        return $user;
    }

    /**
     * Require user to have any of the specified roles
     */
    protected function requireAnyRole(array $roleNames): array {
        $user = $this->requireAuth();

        if (!$this->hasAnyRole($user, $roleNames)) {
            http_response_code(403);
            echo json_encode([
                'error' => 'Forbidden',
                'message' => 'Required roles: ' . implode(', ', $roleNames)
            ]);
            exit;
        }

        return $user;
    }

    /**
     * Check if user has a specific permission
     */
    protected function hasPermission(array $user, string $permissionName): bool {
        // Check role permissions
        foreach ($user['roles'] as $role) {
            foreach ($role['permissions'] as $permission) {
                if ($permission['name'] === $permissionName) {
                    return true;
                }
            }
        }

        // Check direct user permissions
        foreach ($user['permissions'] as $permission) {
            if ($permission['name'] === $permissionName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user has any of the specified permissions
     */
    protected function hasAnyPermission(array $user, array $permissionNames): bool {
        foreach ($permissionNames as $permissionName) {
            if ($this->hasPermission($user, $permissionName)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Require user to have specific permission - sends 403 if not authorized
     */
    protected function requirePermission(string $permissionName): array {
        $user = $this->requireAuth();

        if (!$this->hasPermission($user, $permissionName)) {
            http_response_code(403);
            echo json_encode([
                'error' => 'Forbidden',
                'message' => "Required permission: {$permissionName}"
            ]);
            exit;
        }

        return $user;
    }

    /**
     * Require user to have any of the specified permissions
     */
    protected function requireAnyPermission(array $permissionNames): array {
        $user = $this->requireAuth();

        if (!$this->hasAnyPermission($user, $permissionNames)) {
            http_response_code(403);
            echo json_encode([
                'error' => 'Forbidden',
                'message' => 'Required permissions: ' . implode(', ', $permissionNames)
            ]);
            exit;
        }

        return $user;
    }

    /**
     * Check if user can perform action on resource with optional ownership check
     *
     * @param string $action Action to perform (e.g., 'update', 'delete')
     * @param string $resource Resource name (e.g., 'articles', 'users')
     * @param array|null $entity Optional entity for ownership check
     * @return bool
     */
    protected function can(array $user, string $action, string $resource, ?array $entity = null): bool {
        // Check "any" scope first
        if ($this->hasPermission($user, "{$resource}.{$action}.any")) {
            return true;
        }

        // Check "own" scope with entity
        if ($entity && $this->hasPermission($user, "{$resource}.{$action}.own")) {
            return $this->userOwnsEntity($user, $entity);
        }

        return false;
    }

    /**
     * Require user to be able to perform action on resource
     */
    protected function requireCan(string $action, string $resource, ?array $entity = null): array {
        $user = $this->requireAuth();

        if (!$this->can($user, $action, $resource, $entity)) {
            http_response_code(403);
            echo json_encode([
                'error' => 'Forbidden',
                'message' => "You cannot {$action} this {$resource}"
            ]);
            exit;
        }

        return $user;
    }

    /**
     * Check if entity belongs to user
     */
    protected function userOwnsEntity(array $user, array $entity): bool {
        // Check various ownership patterns
        if (isset($entity['user_id']) && $entity['user_id'] == $user['users_id']) {
            return true;
        }

        if (isset($entity['author_id']) && $entity['author_id'] == $user['users_id']) {
            return true;
        }

        if (isset($entity['created_by']) && $entity['created_by'] == $user['users_id']) {
            return true;
        }

        return false;
    }
}
