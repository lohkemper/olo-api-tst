<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Specialized POST handler for auth-related endpoints
 * Handles: POST /api/auth/refresh
 */
class requestPostAuth extends RequestBase {
    private array $request = [];
    private array $data = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            $this->log('requestPostAuth::execute');
            $this->log(['requestPostAuth::request', $this->request]);
            $this->log(['requestPostAuth::data', $this->data]);

            // Determine which auth endpoint is being called
            $subRoute = $this->request['subroute'] ?? '';

            switch ($subRoute) {
                case 'refresh':
                    $this->handleRefreshToken();
                    break;

                case 'settings':
                    $this->handleUpdateSettings();
                    break;

                default:
                    http_response_code(404);
                    echo json_encode(['error' => 'Auth endpoint not found']);
                    break;
            }

        } catch (\Throwable $e) {
            $this->handleError('Error executing POST auth request', $e);
        }
    }

    /**
     * POST /api/auth/refresh
     * Refreshes the JWT token and returns a new one
     */
    private function handleRefreshToken(): void {
        // Require authentication
        $user = $this->requireAuth();

        // Session neu ausstellen (frisches JWT-Cookie + CSRF) — zentral in
        // JwtSession; Antwort im Login-Format { user, csrfToken }.
        $response = JwtSession::issue($this->pdo, $user);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($response);
    }

    /**
     * POST /api/auth/settings
     * Aktualisiert die UI-Präferenzen (theme/density/accent) des eingeloggten
     * Users. Reines Selbst-Update — keine Admin-Permission nötig.
     */
    private function handleUpdateSettings(): void {
        CsrfHelper::requireValidToken();
        $user = $this->requireAuth();
        $userId = (int)($user['users_id'] ?? $user['id'] ?? 0);
        if ($userId <= 0) {
            http_response_code(401);
            echo json_encode(['error' => 'Not authenticated']);
            return;
        }

        $allowed = [
            'theme' => ['light', 'dark', 'auto'],
            'density' => ['comfortable', 'compact'],
            'accent' => ['sodium', 'cyan', 'acid', 'plasma', 'amber'],
        ];

        $sets = [];
        $params = ['id' => $userId];
        foreach ($allowed as $field => $valid) {
            if (!array_key_exists($field, $this->data)) {
                continue;
            }
            $value = $this->data[$field];
            if ($value !== null && !in_array($value, $valid, true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid value for ' . $field]);
                return;
            }
            $sets[] = "`$field` = :$field";
            $params[$field] = $value;
        }

        if (empty($sets)) {
            http_response_code(400);
            echo json_encode(['error' => 'No settings provided']);
            return;
        }

        $stmt = $this->pdo->prepare(
            "UPDATE " . PREFIX . "_users SET " . implode(', ', $sets) . " WHERE users_id = :id"
        );
        $stmt->execute($params);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    }

}
