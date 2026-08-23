<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Gemeinsamer Session-Aussteller für die Social-Login-Endpoints.
 *
 * Bündelt: JWT erzeugen (firebase/php-jwt, wie post-login), HttpOnly-Cookie
 * setzen (korrekte $isSecure ? 'None' : 'Lax'-Logik), CSRF-Token erzeugen und
 * das camelCase-Wire-Format der /auth/login-Antwort bauen.
 *
 * Die bestehenden Implementierungen in post-login/post-register/post-auth
 * bleiben bewusst unangetastet (Follow-up: Vereinheitlichung auf diesen Helper).
 */
final class JwtSession {

    /**
     * Stellt die komplette Session aus (JWT-Cookie + CSRF) und liefert die
     * Login-kompatible Antwort { user, csrfToken }.
     * @param array $userRow  Zeile aus mbc_users (users_id, email, username, …)
     */
    public static function issue(PDO $pdo, array $userRow): array {
        $userId = (int)$userRow['users_id'];

        $roles = self::loadUserRoles($pdo, $userId);
        $permissions = self::loadUserDirectPermissions($pdo, $userId);

        $token = self::generateJwtToken($userRow);
        self::setJwtCookie($token);
        $csrfToken = CsrfHelper::generateToken();

        return [
            'user' => self::buildUserResponse($userRow, $roles, $permissions),
            'csrfToken' => $csrfToken,
        ];
    }

    /** camelCase-Wire-Format identisch zu POST /auth/login. */
    private static function buildUserResponse(array $user, array $roles, array $permissions): array {
        $response = [
            'id' => (int)$user['users_id'],
            'email' => $user['email'],
            'username' => $user['username'],
            'firstName' => $user['first_name'] ?? null,
            'lastName' => $user['last_name'] ?? null,
            'roles' => $roles,
            'permissions' => $permissions,
        ];
        // UI-Präferenzen nur mitliefern, wenn gesetzt (NULL → Client-Default).
        foreach (['theme', 'density', 'accent'] as $pref) {
            if (!empty($user[$pref])) {
                $response[$pref] = $user[$pref];
            }
        }
        return $response;
    }

    private static function loadUserRoles(PDO $pdo, int $userId): array {
        $stmt = $pdo->prepare("
            SELECT r.roles_id, r.name, r.display_name, r.description
            FROM " . PREFIX . "_roles r
            INNER JOIN " . PREFIX . "_user_roles ur ON r.roles_id = ur.role_id
            WHERE ur.user_id = :userId
        ");
        $stmt->execute(['userId' => $userId]);
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($roles as &$role) {
            $role['roles_id'] = (int)$role['roles_id'];
            $role['permissions'] = self::loadRolePermissions($pdo, (int)$role['roles_id']);
        }
        return $roles;
    }

    private static function loadRolePermissions(PDO $pdo, int $roleId): array {
        $stmt = $pdo->prepare("
            SELECT p.permissions_id, p.name, p.resource, p.action, p.scope, p.description
            FROM " . PREFIX . "_permissions p
            INNER JOIN " . PREFIX . "_role_permissions rp ON p.permissions_id = rp.permission_id
            WHERE rp.role_id = :roleId
        ");
        $stmt->execute(['roleId' => $roleId]);
        return array_map([self::class, 'mapPermission'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function loadUserDirectPermissions(PDO $pdo, int $userId): array {
        $stmt = $pdo->prepare("
            SELECT p.permissions_id, p.name, p.resource, p.action, p.scope, p.description
            FROM " . PREFIX . "_permissions p
            INNER JOIN " . PREFIX . "_user_permissions up ON p.permissions_id = up.permission_id
            WHERE up.user_id = :userId
            AND (up.expires_at IS NULL OR up.expires_at > NOW())
        ");
        $stmt->execute(['userId' => $userId]);
        return array_map([self::class, 'mapPermission'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function mapPermission(array $permission): array {
        return [
            'id' => (int)$permission['permissions_id'],
            'name' => $permission['name'],
            'resource' => $permission['resource'],
            'action' => $permission['action'],
            'scope' => $permission['scope'],
            'description' => $permission['description'] ?? '',
        ];
    }

    private static function generateJwtToken(array $user): string {
        $secretKey = $_ENV['JWT_SECRET'];
        $issuedAt = time();

        $payload = [
            'iss' => $_SERVER['HTTP_HOST'] ?? 'mbc-api',
            'aud' => $_SERVER['HTTP_HOST'] ?? 'mbc-api',
            'iat' => $issuedAt,
            'exp' => $issuedAt + (60 * 60 * 24), // 24 hours
            'userId' => $user['users_id'],
            'username' => $user['username'],
            'email' => $user['email'],
        ];

        return \Firebase\JWT\JWT::encode($payload, $secretKey, 'HS256');
    }

    private static function setJwtCookie(string $token): void {
        $isSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
                    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        setcookie('jwt_token', $token, [
            'expires' => time() + (60 * 60 * 24), // 24 hours (same as JWT exp)
            'path' => '/',
            'domain' => '',
            'secure' => $isSecure,
            'httponly' => true,
            // SameSite=None braucht Secure (HTTPS); im Dev über HTTP → Lax.
            'samesite' => $isSecure ? 'None' : 'Lax',
        ]);
    }
}
