<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Social-Login-Provider (Google + Facebook) — Authorization-Code-Flow, server-side.
 * Pure PHP + cURL, ohne Composer-Abhängigkeit (Muster: auth/google-oauth.php).
 *
 * Konfiguration über .env (von cfg.php via phpdotenv geladen):
 *   GOOGLE_LOGIN_CLIENT_ID / GOOGLE_LOGIN_CLIENT_SECRET / GOOGLE_LOGIN_REDIRECT_URI
 *   FACEBOOK_LOGIN_APP_ID / FACEBOOK_LOGIN_APP_SECRET / FACEBOOK_LOGIN_REDIRECT_URI
 *   FACEBOOK_GRAPH_VERSION            (Default v23.0)
 *   SOCIAL_LOGIN_SPA_RETURN_URL       Prod-SPA-Basis, z.B. https://oliverlohkemper.de/mbc
 *   SOCIAL_LOGIN_ALLOWED_DEV_ORIGINS  Komma-Liste, z.B. http://localhost:4200,http://localhost:4202
 *
 * Die Klassen liefern nur die Identität (kein offline-Access, keine Token-Persistenz);
 * einheitliches Profil-DTO: providerUserId, email, emailVerified, displayName,
 * firstName, lastName, avatarUrl.
 *
 * Spec: docs/design/security.md (OAuth 2.0, state-Param, kein Token-Leak ans FE).
 */

abstract class SocialLoginProvider {
    public const PROVIDERS = ['google', 'facebook'];

    /** Factory: wirft bei unbekanntem Provider. */
    public static function for(string $provider): self {
        switch ($provider) {
            case 'google': return new GoogleLoginProvider();
            case 'facebook': return new FacebookLoginProvider();
        }
        throw new \InvalidArgumentException('Unknown social provider: ' . $provider);
    }

    abstract public function name(): string;
    abstract public function isConfigured(): bool;
    abstract public function buildAuthUrl(string $state): string;

    /**
     * Tauscht den Authorization-Code und liefert das Profil-DTO.
     * @return array{providerUserId:string, email:string, emailVerified:bool,
     *               displayName:string, firstName:string, lastName:string, avatarUrl:string}
     */
    abstract public function fetchProfile(string $code): array;

    /**
     * SPA-Return-Basis für den Rücksprung nach dem Callback. Der übergebene
     * Origin (aus dem social-url-Request) wird gegen die .env-Whitelist
     * gematcht — kein Treffer → Prod-Default. Verhindert Open Redirects.
     */
    public static function resolveReturnBase(string $origin): string {
        $prodBase = (string)($_ENV['SOCIAL_LOGIN_SPA_RETURN_URL'] ?? '');
        $devOrigins = array_filter(array_map('trim',
            explode(',', (string)($_ENV['SOCIAL_LOGIN_ALLOWED_DEV_ORIGINS'] ?? ''))));
        foreach ($devOrigins as $allowed) {
            if ($origin !== '' && $origin === $allowed) {
                return rtrim($allowed, '/');
            }
        }
        return rtrim($prodBase, '/');
    }

    // ---- HTTP-Helfer ---------------------------------------------------------

    /** @return array<string,mixed> */
    protected function postForm(string $url, array $fields): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException($this->name() . ' OAuth HTTP error: ' . $err);
        }
        $decoded = json_decode((string)$body, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string,mixed> */
    protected function getJson(string $url): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException($this->name() . ' OAuth HTTP error: ' . $err);
        }
        $decoded = json_decode((string)$body, true);
        return is_array($decoded) ? $decoded : [];
    }
}

final class GoogleLoginProvider extends SocialLoginProvider {
    private const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    // Nur Identität — kein access_type=offline, kein prompt=consent (kein Refresh-Token nötig).
    private const SCOPE = 'openid email profile';

    public function name(): string { return 'google'; }

    private function clientId(): string { return (string)($_ENV['GOOGLE_LOGIN_CLIENT_ID'] ?? ''); }
    private function clientSecret(): string { return (string)($_ENV['GOOGLE_LOGIN_CLIENT_SECRET'] ?? ''); }
    private function redirectUri(): string { return (string)($_ENV['GOOGLE_LOGIN_REDIRECT_URI'] ?? ''); }

    public function isConfigured(): bool {
        return $this->clientId() !== '' && $this->clientSecret() !== '' && $this->redirectUri() !== '';
    }

    public function buildAuthUrl(string $state): string {
        return self::AUTH_ENDPOINT . '?' . http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'state' => $state,
        ]);
    }

    public function fetchProfile(string $code): array {
        $resp = $this->postForm(self::TOKEN_ENDPOINT, [
            'code' => $code,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
        ]);
        $idToken = (string)($resp['id_token'] ?? '');
        if ($idToken === '') {
            throw new \RuntimeException('Google OAuth: no id_token in token response');
        }
        $claims = $this->decodeIdToken($idToken);
        return [
            'providerUserId' => (string)($claims['sub'] ?? ''),
            'email' => strtolower(trim((string)($claims['email'] ?? ''))),
            'emailVerified' => (bool)($claims['email_verified'] ?? false),
            'displayName' => (string)($claims['name'] ?? ''),
            'firstName' => (string)($claims['given_name'] ?? ''),
            'lastName' => (string)($claims['family_name'] ?? ''),
            'avatarUrl' => (string)($claims['picture'] ?? ''),
        ];
    }

    /**
     * Dekodiert das id_token-Payload und prüft aud/iss/exp. Auf die Signatur-
     * prüfung (JWKS) wird verzichtet: das Token kommt via TLS direkt vom
     * Google-Token-Endpoint (OIDC Core 3.1.3.7 erlaubt das für diesen Fall).
     * @return array<string,mixed>
     */
    private function decodeIdToken(string $idToken): array {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Google OAuth: malformed id_token');
        }
        $payload = json_decode(
            (string)base64_decode(strtr($parts[1], '-_', '+/'), true),
            true
        );
        if (!is_array($payload)) {
            throw new \RuntimeException('Google OAuth: undecodable id_token payload');
        }
        $issOk = in_array($payload['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true);
        $audOk = ($payload['aud'] ?? '') === $this->clientId();
        $expOk = (int)($payload['exp'] ?? 0) > time();
        if (!$issOk || !$audOk || !$expOk) {
            throw new \RuntimeException('Google OAuth: id_token claim validation failed');
        }
        return $payload;
    }
}

final class FacebookLoginProvider extends SocialLoginProvider {
    public function name(): string { return 'facebook'; }

    private function appId(): string { return (string)($_ENV['FACEBOOK_LOGIN_APP_ID'] ?? ''); }
    private function appSecret(): string { return (string)($_ENV['FACEBOOK_LOGIN_APP_SECRET'] ?? ''); }
    private function redirectUri(): string { return (string)($_ENV['FACEBOOK_LOGIN_REDIRECT_URI'] ?? ''); }
    private function graphVersion(): string { return (string)($_ENV['FACEBOOK_GRAPH_VERSION'] ?? 'v23.0'); }

    public function isConfigured(): bool {
        return $this->appId() !== '' && $this->appSecret() !== '' && $this->redirectUri() !== '';
    }

    public function buildAuthUrl(string $state): string {
        return 'https://www.facebook.com/' . $this->graphVersion() . '/dialog/oauth?' . http_build_query([
            'client_id' => $this->appId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'public_profile,email',
            'state' => $state,
        ]);
    }

    public function fetchProfile(string $code): array {
        $graph = 'https://graph.facebook.com/' . $this->graphVersion();
        $tokenResp = $this->getJson($graph . '/oauth/access_token?' . http_build_query([
            'client_id' => $this->appId(),
            'client_secret' => $this->appSecret(),
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
        ]));
        $accessToken = (string)($tokenResp['access_token'] ?? '');
        if ($accessToken === '') {
            throw new \RuntimeException('Facebook OAuth: no access_token in token response');
        }
        // appsecret_proof: bindet den Call an das App-Secret (Pflicht-Setting der App).
        $me = $this->getJson($graph . '/me?' . http_build_query([
            'fields' => 'id,name,email,first_name,last_name,picture.type(large)',
            'access_token' => $accessToken,
            'appsecret_proof' => hash_hmac('sha256', $accessToken, $this->appSecret()),
        ]));
        if (empty($me['id'])) {
            throw new \RuntimeException('Facebook OAuth: profile fetch failed');
        }
        return [
            'providerUserId' => (string)$me['id'],
            // Facebook liefert email nur für verifizierte Konten → verified implizit.
            'email' => strtolower(trim((string)($me['email'] ?? ''))),
            'emailVerified' => !empty($me['email']),
            'displayName' => (string)($me['name'] ?? ''),
            'firstName' => (string)($me['first_name'] ?? ''),
            'lastName' => (string)($me['last_name'] ?? ''),
            'avatarUrl' => (string)($me['picture']['data']['url'] ?? ''),
        ];
    }
}
