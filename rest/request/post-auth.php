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

        // Generate new JWT token
        $newToken = $this->generateJwtToken($user);

        // Set JWT as HttpOnly Cookie for security
        $this->setJwtCookie($newToken);

        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Generate new CSRF token
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        // Response (without accessToken in body - it's now in cookie)
        $response = [
            'csrfToken' => $_SESSION['csrf_token'],
            'user' => [
                'id' => (int)($user['users_id'] ?? $user['id'] ?? 0),
                'email' => $user['email'],
                'username' => $user['username'],
                'bio' => $user['bio'] ?? '',
                'image' => $user['image'] ?? ''
            ]
        ];

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

    /**
     * Generate JWT token for user
     * Simplified version - in production use a proper JWT library
     */
    private function generateJwtToken(array $user): string {
        $header = json_encode([
            'typ' => 'JWT',
            'alg' => 'HS256'
        ]);

        $payload = json_encode([
            'userId' => $user['users_id'] ?? $user['id'] ?? null,
            'username' => $user['username'],
            'email' => $user['email'],
            'iat' => time(),
            'exp' => time() + (60 * 60 * 24) // 24 hours
        ]);

        // Base64 encode
        $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));

        // Create signature
        $secretKey = $_ENV['JWT_SECRET'] ?? 'your-secret-key-change-in-production';
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secretKey, true);
        $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

        // Create JWT
        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    /**
     * Set JWT token as HttpOnly Cookie
     * Provides XSS protection by making token inaccessible to JavaScript
     */
    private function setJwtCookie(string $token): void {
        // Determine if we're in a secure context (HTTPS)
        $isSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
                    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        setcookie(
            'jwt_token',                              // Cookie name
            $token,                                   // JWT token value
            [
                'expires' => time() + (60 * 60 * 24), // 24 hours (same as JWT exp)
                'path' => '/',                        // Available across entire domain
                'domain' => '',                       // Current domain
                'secure' => $isSecure,                // Only over HTTPS in production
                'httponly' => true,                   // Not accessible via JavaScript (XSS protection)
                'samesite' => 'None'                  // Allow cross-site requests (needed for localhost development)
            ]
        );
    }
}
