<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Logout Handler
 * Handles: POST /api/auth/logout
 */
class requestPostLogout extends RequestBase {
    public function execute(): void {
        try {
            $this->log('requestPostLogout::execute');

            // Delete JWT cookie by setting it to expire in the past
            $this->deleteJwtCookie();

            // Clear CSRF token
            CsrfHelper::clearToken();

            // Destroy session
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }

            // Response
            $response = [
                'message' => 'Logged out successfully'
            ];

            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode($response);

        } catch (\Throwable $e) {
            $this->handleError('Error during logout', $e);
        }
    }

    /**
     * Delete JWT cookie by setting expiration to the past
     */
    private function deleteJwtCookie(): void {
        // Determine if we're in a secure context (HTTPS)
        $isSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
                    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        setcookie(
            'jwt_token',                  // Cookie name
            '',                          // Empty value
            [
                'expires' => time() - 3600,   // Expire in the past (1 hour ago)
                'path' => '/',
                'domain' => '',
                'secure' => $isSecure,
                'httponly' => true,
                'samesite' => 'None'      // Must match the SameSite setting used when creating the cookie
            ]
        );
    }
}
