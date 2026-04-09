<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * CSRF Protection Helper
 * Provides Cross-Site Request Forgery protection for state-changing operations
 */
class CsrfHelper {
    private const TOKEN_LENGTH = 32;
    private const SESSION_KEY = 'csrf_token';

    /**
     * Generate a new CSRF token and store in session
     */
    public static function generateToken(): string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $token = bin2hex(random_bytes(self::TOKEN_LENGTH));
        $_SESSION[self::SESSION_KEY] = $token;

        return $token;
    }

    /**
     * Get current CSRF token from session
     */
    public static function getToken(): ?string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        return $_SESSION[self::SESSION_KEY] ?? null;
    }

    /**
     * Validate CSRF token from request
     *
     * @param string|null $token Token from request (header or body)
     * @return bool True if valid, false otherwise
     */
    public static function validateToken(?string $token): bool {
        if (!$token) {
            return false;
        }

        $sessionToken = self::getToken();

        if (!$sessionToken) {
            return false;
        }

        // Use hash_equals to prevent timing attacks
        return hash_equals($sessionToken, $token);
    }

    /**
     * Require valid CSRF token - sends 403 if invalid
     * Use this in POST/PUT/DELETE handlers
     */
    public static function requireValidToken(): void {
        // Get token from header (preferred) or request body
        $headers = getallheaders();

        // Case-insensitive header lookup for X-CSRF-Token
        $token = null;
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'x-csrf-token') {
                $token = $value;
                break;
            }
        }

        // Fallback to POST body or JSON body
        if (!$token) {
            $token = $_POST['csrf_token'] ?? null;
        }

        // For JSON requests, check the decoded body
        if (!$token && isset($GLOBALS['_PUT'])) {
            $token = $GLOBALS['_PUT']['csrf_token'] ?? null;
        }

        if (!self::validateToken($token)) {
            http_response_code(403);
            echo json_encode([
                'error' => 'Forbidden',
                'message' => 'Invalid or missing CSRF token'
            ]);
            exit;
        }
    }

    /**
     * Refresh CSRF token (e.g., after login)
     */
    public static function refreshToken(): string {
        return self::generateToken();
    }

    /**
     * Clear CSRF token (e.g., on logout)
     */
    public static function clearToken(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        unset($_SESSION[self::SESSION_KEY]);
    }
}
