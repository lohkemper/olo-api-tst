<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Login Handler
 * Handles: POST /api/auth/login
 */
class requestPostLogin extends RequestBase {
    private array $data = [];

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            // Rate limiting: Max 5 login attempts per 5 minutes per IP
            $rateLimiter = new RateLimiter($this->pdo);
            $rateLimiter->requireLimit('login', 5, 300);

            $this->log('requestPostLogin::execute');
            $this->log(['requestPostLogin::data', $this->data]);

            // Validate input
            if (!isset($this->data['email']) || !isset($this->data['password'])) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Bad Request',
                    'message' => 'Email and password are required'
                ]);
                return;
            }

            $email = trim($this->data['email']);
            $password = $this->data['password'];

            // Find user by email
            $stmt = $this->pdo->prepare("
                SELECT
                    users_id,
                    email,
                    username,
                    password_hash,
                    first_name,
                    last_name,
                    theme,
                    density,
                    accent
                FROM " . PREFIX . "_users
                WHERE email = :email
                LIMIT 1
            ");

            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                Logger::logSecurityEvent('Failed login attempt - user not found', ['email' => $email]);
                http_response_code(401);
                echo json_encode([
                    'error' => 'Unauthorized',
                    'message' => 'Invalid email or password'
                ]);
                return;
            }

            // Verify password. Social-only-Konten haben password_hash NULL —
            // ohne Guard würde password_verify() unter strict_types mit 500 abbrechen.
            if (empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
                Logger::logSecurityEvent('Failed login attempt - invalid password', ['email' => $email]);
                http_response_code(401);
                echo json_encode([
                    'error' => 'Unauthorized',
                    'message' => 'Invalid email or password'
                ]);
                return;
            }

            // MFA-Challenge: hat der User bestätigte Methoden und kein gültiges
            // Trusted-Device-Cookie, wird KEIN JWT ausgestellt — stattdessen
            // Pending-Session + mfaRequired-Antwort (Plan: docs/planning/mfa-2fa.md).
            // Ohne MFA bleibt der Bestandspfad byte-identisch.
            if (class_exists('MfaHelper')) {
                $mfaMethods = MfaHelper::confirmedMethods($this->pdo, (int)$user['users_id']);
                if ($mfaMethods && !MfaHelper::isTrustedDevice($this->pdo, (int)$user['users_id'])) {
                    MfaHelper::beginChallenge($this->pdo, (int)$user['users_id'], $mfaMethods, 'login');
                    // Passwort-Stufe bestanden — mfa-verify hat ein eigenes Limit.
                    $rateLimiter->reset('login');
                    Logger::info('MFA challenge started', ['user_id' => $user['users_id'], 'origin' => 'login']);
                    http_response_code(200);
                    header('Content-Type: application/json');
                    echo json_encode([
                        'mfaRequired' => true,
                        'methods' => $mfaMethods,
                        'backupCodesAvailable' => MfaHelper::backupCodesRemaining($this->pdo, (int)$user['users_id']) > 0,
                        'csrfToken' => CsrfHelper::generateToken(),
                    ]);
                    return;
                }
            }

            // Update last_login timestamp
            $updateStmt = $this->pdo->prepare("
                UPDATE " . PREFIX . "_users
                SET last_login = NOW()
                WHERE users_id = :id
            ");
            $updateStmt->execute(['id' => $user['users_id']]);

            // Remove password from user array
            unset($user['password_hash']);

            // Load user roles and permissions
            $user['roles'] = $this->getUserRoles((int)$user['users_id']);
            $user['permissions'] = $this->getUserPermissions((int)$user['users_id']);

            // Reset rate limit on successful login
            $rateLimiter->reset('login');

            // Generate JWT token
            $token = $this->generateJwtToken($user);

            // Set JWT as HttpOnly Cookie for security
            $this->setJwtCookie($token);

            // Generate CSRF token for subsequent requests
            $csrfToken = CsrfHelper::generateToken();

            // Log successful login
            Logger::info('Successful login', [
                'user_id' => $user['users_id'],
                'email' => $email,
                'username' => $user['username']
            ]);

            // Response (without accessToken in body - it's now in cookie)
            $response = [
                'user' => [
                    'id' => (int)$user['users_id'],
                    'email' => $user['email'],
                    'username' => $user['username'],
                    'firstName' => $user['first_name'] ?? null,
                    'lastName' => $user['last_name'] ?? null,
                    'roles' => $user['roles'],
                    'permissions' => $user['permissions']
                ],
                'csrfToken' => $csrfToken
            ];

            // UI-Präferenzen nur mitliefern, wenn gesetzt (NULL → Client-Default).
            foreach (['theme', 'density', 'accent'] as $pref) {
                if (!empty($user[$pref])) {
                    $response['user'][$pref] = $user[$pref];
                }
            }

            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode($response);

        } catch (\Throwable $e) {
            $this->handleError('Error during login', $e);
        }
    }

    /**
     * Get user roles with permissions
     */
    private function getUserRoles(int $userId): array {
        $stmt = $this->pdo->prepare("
            SELECT
                r.roles_id,
                r.name,
                r.display_name,
                r.description
            FROM " . PREFIX . "_roles r
            INNER JOIN " . PREFIX . "_user_roles ur ON r.roles_id = ur.role_id
            WHERE ur.user_id = :userId
        ");

        $stmt->execute(['userId' => $userId]);
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Load permissions for each role
        foreach ($roles as &$role) {
            $role['roles_id'] = (int)$role['roles_id'];
            $role['permissions'] = $this->getRolePermissions((int)$role['roles_id']);
        }

        return $roles;
    }

    /**
     * Get role permissions
     */
    private function getRolePermissions(int $roleId): array {
        $stmt = $this->pdo->prepare("
            SELECT
                p.permissions_id,
                p.name,
                p.resource,
                p.action,
                p.scope,
                p.description
            FROM " . PREFIX . "_permissions p
            INNER JOIN " . PREFIX . "_role_permissions rp ON p.permissions_id = rp.permission_id
            WHERE rp.role_id = :roleId
        ");

        $stmt->execute(['roleId' => $roleId]);
        $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function($permission) {
            return [
                'id' => (int)$permission['permissions_id'],
                'name' => $permission['name'],
                'resource' => $permission['resource'],
                'action' => $permission['action'],
                'scope' => $permission['scope'],
                'description' => $permission['description'] ?? ''
            ];
        }, $permissions);
    }

    /**
     * Get user-specific permissions
     */
    private function getUserPermissions(int $userId): array {
        $stmt = $this->pdo->prepare("
            SELECT
                p.permissions_id,
                p.name,
                p.resource,
                p.action,
                p.scope,
                p.description
            FROM " . PREFIX . "_permissions p
            INNER JOIN " . PREFIX . "_user_permissions up ON p.permissions_id = up.permission_id
            WHERE up.user_id = :userId
            AND (up.expires_at IS NULL OR up.expires_at > NOW())
        ");

        $stmt->execute(['userId' => $userId]);
        $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function($permission) {
            return [
                'id' => (int)$permission['permissions_id'],
                'name' => $permission['name'],
                'resource' => $permission['resource'],
                'action' => $permission['action'],
                'scope' => $permission['scope'],
                'description' => $permission['description'] ?? ''
            ];
        }, $permissions);
    }

    /**
     * Generate JWT token using firebase/php-jwt library
     */
    private function generateJwtToken(array $user): string {
        $secretKey = $_ENV['JWT_SECRET'];
        $issuedAt = time();
        $expirationTime = $issuedAt + (60 * 60 * 24); // 24 hours

        $payload = [
            'iss' => $_SERVER['HTTP_HOST'] ?? 'mbc-api',  // Issuer
            'aud' => $_SERVER['HTTP_HOST'] ?? 'mbc-api',  // Audience
            'iat' => $issuedAt,                            // Issued at
            'exp' => $expirationTime,                      // Expiration time
            'userId' => $user['users_id'],
            'username' => $user['username'],
            'email' => $user['email']
        ];

        return \Firebase\JWT\JWT::encode($payload, $secretKey, 'HS256');
    }

    /**
     * Set JWT token as HttpOnly Cookie
     * Provides XSS protection by making token inaccessible to JavaScript
     */
    private function setJwtCookie(string $token): void {
        // Determine if we're in a secure context (HTTPS)
        $isSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
                    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        // SameSite must be 'Lax' or 'Strict' in development (HTTP)
        // SameSite 'None' requires Secure=true (HTTPS only)
        $sameSite = $isSecure ? 'None' : 'Lax';

        setcookie(
            'jwt_token',                              // Cookie name
            $token,                                   // JWT token value
            [
                'expires' => time() + (60 * 60 * 24), // 24 hours (same as JWT exp)
                'path' => '/',                        // Available across entire domain
                'domain' => '',                       // Current domain
                'secure' => $isSecure,                // Only over HTTPS in production
                'httponly' => true,                   // Not accessible via JavaScript (XSS protection)
                'samesite' => $sameSite               // Lax for dev (HTTP), None for prod (HTTPS)
            ]
        );
    }
}
