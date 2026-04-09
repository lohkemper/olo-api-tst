<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Debug endpoint for troubleshooting authentication issues
 * Returns detailed information about cookies, headers, and session
 * IMPORTANT: Remove this in production!
 */
class requestGetDebug extends RequestBase {
    public function execute(): void {
        try {
            $this->log('requestGetDebug::execute');

            // Get token using AuthHelper
            $token = $this->authHelper->getToken();

            // Get all headers
            $headers = getallheaders();

            // Debug information
            $debugInfo = [
                'cookies' => [
                    'all_cookies' => $_COOKIE,
                    'jwt_token_exists' => isset($_COOKIE['jwt_token']),
                    'jwt_token_value' => $_COOKIE['jwt_token'] ?? null,
                    'jwt_token_length' => isset($_COOKIE['jwt_token']) ? strlen($_COOKIE['jwt_token']) : 0
                ],
                'headers' => [
                    'all_headers' => $headers,
                    'authorization_exists' => isset($headers['Authorization']),
                    'authorization_value' => $headers['Authorization'] ?? null,
                    'origin' => $headers['Origin'] ?? null,
                    'referer' => $headers['Referer'] ?? null,
                    'cookie_header' => $headers['Cookie'] ?? null
                ],
                'auth_helper' => [
                    'token_found' => $token !== null,
                    'token_length' => $token ? strlen($token) : 0,
                    'token_first_10_chars' => $token ? substr($token, 0, 10) : null
                ],
                'server_info' => [
                    'https' => $_SERVER['HTTPS'] ?? null,
                    'http_x_forwarded_proto' => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null,
                    'server_name' => $_SERVER['SERVER_NAME'] ?? null,
                    'request_uri' => $_SERVER['REQUEST_URI'] ?? null
                ],
                'session' => [
                    'session_status' => session_status(),
                    'session_id' => session_status() === PHP_SESSION_ACTIVE ? session_id() : null
                ]
            ];

            // Try to validate token if found
            if ($token) {
                $payload = $this->authHelper->validateToken($token);
                $debugInfo['token_validation'] = [
                    'is_valid' => $payload !== null,
                    'payload' => $payload
                ];

                // Try to get current user
                $user = $this->authHelper->getCurrentUser();
                $debugInfo['current_user'] = [
                    'user_found' => $user !== null,
                    'user_id' => $user['id'] ?? null,
                    'user_email' => $user['email'] ?? null,
                    'roles_count' => isset($user['roles']) ? count($user['roles']) : 0,
                    'permissions_count' => isset($user['permissions']) ? count($user['permissions']) : 0
                ];
            }

            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode($debugInfo, JSON_PRETTY_PRINT);

        } catch (\Throwable $e) {
            $this->handleError('Debug endpoint error', $e);
        }
    }
}
