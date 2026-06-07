<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Specialized DELETE handler for users endpoint (Admin)
 * Handles: DELETE /api/users/{id}
 *
 * Löscht einen Benutzer. user_roles/user_permissions werden per FK-Cascade
 * entfernt; defensiv zusätzlich manuell. Erfordert users.delete.any oder admin.access.
 * Selbstlöschung wird verhindert.
 */
class requestDeleteUsers extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            $this->log('requestDeleteUsers::execute');
            $this->log(['requestDeleteUsers::request', $this->request]);

            $currentUser = $this->requireAnyPermission(['users.delete.any', 'admin.access']);

            if (!isset($this->request['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'User ID is required']);
                return;
            }
            $userId = is_array($this->request['id']) ? (int)$this->request['id'][0] : (int)$this->request['id'];

            $currentId = (int)($currentUser['users_id'] ?? $currentUser['id'] ?? 0);
            if ($currentId === $userId) {
                http_response_code(409);
                echo json_encode(['error' => 'You cannot delete your own account']);
                return;
            }

            $user = $this->getUserRow($userId);
            if (!$user) {
                http_response_code(404);
                echo json_encode(['error' => 'User not found']);
                return;
            }

            // Defensiv: Verknüpfungen entfernen (FK-Cascade vorausgesetzt, aber sicher ist sicher)
            foreach (['user_roles', 'user_permissions'] as $table) {
                $del = $this->pdo->prepare("DELETE FROM " . PREFIX . "_$table WHERE user_id = :id");
                $del->execute(['id' => $userId]);
            }

            $stmt = $this->pdo->prepare("DELETE FROM " . PREFIX . "_users WHERE users_id = :id");
            $stmt->execute(['id' => $userId]);

            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'User deleted successfully',
                'deleted_user' => [
                    'id' => (int)$user['users_id'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                ],
            ]);

        } catch (\Throwable $e) {
            $this->handleError('Error deleting user', $e);
        }
    }

    private function getUserRow(int $userId): array|false {
        $stmt = $this->pdo->prepare(
            "SELECT users_id, username, email FROM " . PREFIX . "_users WHERE users_id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
