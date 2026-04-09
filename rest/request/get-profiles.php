<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET handler for profiles endpoint
 * Handles: GET /api/profiles/{username}
 *
 * Returns public profile information for a user by username
 */
class requestGetProfiles extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            $this->log('requestGetProfiles::execute');
            $this->log(['requestGetProfiles::request', $this->request]);

            // Require authentication
            $currentUser = $this->requireAuth();

            // Extract username/ID from request
            // The router may set 'id' for numeric IDs, 'subroute' for string usernames, or 'groupby' as fallback
            $identifier = null;

            if (isset($this->request['id'])) {
                // ID can be array (from setPath) or scalar - extract first value
                $identifier = is_array($this->request['id']) ? $this->request['id'][0] : $this->request['id'];
            } elseif (isset($this->request['subroute'])) {
                // String identifier (username) is stored in 'subroute' by the router for non-numeric second path segment
                $identifier = $this->request['subroute'];
            } elseif (isset($this->request['groupby'])) {
                // Fallback: String identifier may also be in 'groupby'
                $identifier = $this->request['groupby'];
            }

            if (!$identifier) {
                http_response_code(400);
                echo json_encode(['error' => 'Username or ID required']);
                return;
            }

            $this->handleGetProfile($identifier);

        } catch (\Throwable $e) {
            $this->handleError('Error executing GET profiles request', $e);
        }
    }

    /**
     * GET /api/profiles/{username}
     * Returns public profile information
     */
    private function handleGetProfile(string|int $identifier): void {
        // Determine if identifier is numeric (user_id) or string (username)
        $isNumeric = is_numeric($identifier);

        $sql = "
            SELECT
                users_id,
                username,
                email,
                first_name,
                last_name,
                created_at
            FROM " . PREFIX . "_users
            WHERE " . ($isNumeric ? "users_id = :identifier" : "username = :identifier") . "
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['identifier' => $identifier]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(404);
            echo json_encode(['error' => 'Profile not found']);
            return;
        }

        // Format as profile response
        $profile = [
            'username' => $user['username'],
            'bio' => ($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''),
            'image' => '', // TODO: Add profile image support
            'following' => false, // TODO: Add following logic if needed
            'loading' => false
        ];

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['profile' => $profile]);
    }
}
