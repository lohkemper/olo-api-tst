<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Secure Session Management Helper
 * Configures PHP sessions with security best practices
 */
class SessionHelper {
    /**
     * Start session with secure settings
     */
    public static function start(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return; // Session already started
        }

        // Determine if we're in a secure context (HTTPS)
        $isSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
                    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        // Configure session settings BEFORE starting session
        ini_set('session.cookie_httponly', '1');  // Prevent JavaScript access
        ini_set('session.use_only_cookies', '1'); // Only use cookies, not URL parameters
        ini_set('session.cookie_samesite', $isSecure ? 'None' : 'Lax'); // CSRF protection

        if ($isSecure) {
            ini_set('session.cookie_secure', '1'); // Only transmit over HTTPS
        }

        // Prevent session fixation attacks
        ini_set('session.use_strict_mode', '1'); // Don't accept uninitialized session IDs

        // Use stronger session ID hashing
        ini_set('session.hash_function', 'sha256');
        ini_set('session.hash_bits_per_character', '5');

        // Session lifetime (24 hours)
        ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24));
        ini_set('session.cookie_lifetime', (string)(60 * 60 * 24));

        // Start the session
        session_start();

        // Regenerate session ID on first request (prevent session fixation)
        if (empty($_SESSION['_initiated'])) {
            session_regenerate_id(true);
            $_SESSION['_initiated'] = true;
            $_SESSION['_created'] = time();
        }

        // Regenerate session ID periodically (every 30 minutes)
        if (isset($_SESSION['_created']) && (time() - $_SESSION['_created'] > 1800)) {
            session_regenerate_id(true);
            $_SESSION['_created'] = time();
        }

        // Validate session user agent (basic fingerprinting)
        self::validateUserAgent();
    }

    /**
     * Validate user agent to detect session hijacking
     */
    private static function validateUserAgent(): void {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

        if (!isset($_SESSION['_user_agent'])) {
            // Store user agent on first session use
            $_SESSION['_user_agent'] = $userAgent;
        } elseif ($_SESSION['_user_agent'] !== $userAgent) {
            // User agent changed - possible session hijacking
            self::destroy();
            http_response_code(401);
            echo json_encode([
                'error' => 'Unauthorized',
                'message' => 'Session validation failed. Please log in again.'
            ]);
            exit;
        }
    }

    /**
     * Regenerate session ID (call after privilege elevation like login)
     */
    public static function regenerate(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['_created'] = time();
        }
    }

    /**
     * Destroy session completely
     */
    public static function destroy(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            // Clear session data
            $_SESSION = [];

            // Delete session cookie
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    [
                        'expires' => time() - 3600,
                        'path' => $params['path'],
                        'domain' => $params['domain'],
                        'secure' => $params['secure'],
                        'httponly' => $params['httponly'],
                        'samesite' => $params['samesite'] ?? 'Lax'
                    ]
                );
            }

            // Destroy session
            session_destroy();
        }
    }

    /**
     * Check if session is expired
     */
    public static function isExpired(): bool {
        if (!isset($_SESSION['_created'])) {
            return true;
        }

        $lifetime = (int)ini_get('session.gc_maxlifetime');
        return (time() - $_SESSION['_created']) > $lifetime;
    }

    /**
     * Get session age in seconds
     */
    public static function getAge(): int {
        if (!isset($_SESSION['_created'])) {
            return 0;
        }

        return time() - $_SESSION['_created'];
    }
}
