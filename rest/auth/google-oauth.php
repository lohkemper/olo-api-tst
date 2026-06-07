<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Google OAuth 2.0 Helper (Authorization Code Flow, server-side) — pure PHP + cURL,
 * ohne Composer-Abhängigkeit.
 *
 * Konfiguration über .env (von cfg.php via phpdotenv geladen):
 *   GOOGLE_OAUTH_CLIENT_ID
 *   GOOGLE_OAUTH_CLIENT_SECRET
 *   GOOGLE_OAUTH_REDIRECT_URI      z.B. https://oliverlohkemper.de/rest2/grow/gcal-callback
 *   GOOGLE_OAUTH_SPA_RETURN_URL    Rücksprung in die SPA, z.B. https://oliverlohkemper.de/mbc/grow/settings
 *
 * Scope: calendar.events (Termine anlegen/ändern für Dünge-Erinnerungen).
 *
 * Spec: docs/design/security.md (OAuth 2.0, state-Param, kein Token-Leak ans FE).
 */

final class GoogleOAuth {
    public const SCOPE = 'https://www.googleapis.com/auth/calendar.events';
    private const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const CALENDAR_API = 'https://www.googleapis.com/calendar/v3';

    public static function clientId(): string { return (string)($_ENV['GOOGLE_OAUTH_CLIENT_ID'] ?? ''); }
    public static function clientSecret(): string { return (string)($_ENV['GOOGLE_OAUTH_CLIENT_SECRET'] ?? ''); }
    public static function redirectUri(): string { return (string)($_ENV['GOOGLE_OAUTH_REDIRECT_URI'] ?? ''); }
    public static function spaReturnUrl(): string { return (string)($_ENV['GOOGLE_OAUTH_SPA_RETURN_URL'] ?? ''); }

    public static function isConfigured(): bool {
        return self::clientId() !== '' && self::clientSecret() !== '' && self::redirectUri() !== '';
    }

    /** Baut die Google-Consent-URL. */
    public static function buildAuthUrl(string $state): string {
        $params = [
            'client_id' => self::clientId(),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',     // Refresh-Token erhalten
            'prompt' => 'consent',          // erzwingt Refresh-Token auch bei Re-Auth
            'include_granted_scopes' => 'true',
            'state' => $state,
        ];
        return self::AUTH_ENDPOINT . '?' . http_build_query($params);
    }

    /**
     * Tauscht den Authorization-Code gegen Tokens.
     * @return array{access_token:string, refresh_token:?string, expires_in:int}
     */
    public static function exchangeCode(string $code): array {
        $resp = self::postForm(self::TOKEN_ENDPOINT, [
            'code' => $code,
            'client_id' => self::clientId(),
            'client_secret' => self::clientSecret(),
            'redirect_uri' => self::redirectUri(),
            'grant_type' => 'authorization_code',
        ]);
        return [
            'access_token' => (string)($resp['access_token'] ?? ''),
            'refresh_token' => isset($resp['refresh_token']) ? (string)$resp['refresh_token'] : null,
            'expires_in' => (int)($resp['expires_in'] ?? 3600),
        ];
    }

    /**
     * Holt einen frischen Access-Token via Refresh-Token.
     * @return array{access_token:string, expires_in:int}
     */
    public static function refreshAccessToken(string $refreshToken): array {
        $resp = self::postForm(self::TOKEN_ENDPOINT, [
            'refresh_token' => $refreshToken,
            'client_id' => self::clientId(),
            'client_secret' => self::clientSecret(),
            'grant_type' => 'refresh_token',
        ]);
        return [
            'access_token' => (string)($resp['access_token'] ?? ''),
            'expires_in' => (int)($resp['expires_in'] ?? 3600),
        ];
    }

    /**
     * Liefert einen gültigen Access-Token für den User; refresht bei Bedarf und
     * persistiert den neuen Token. Gibt null zurück, wenn keine Verbindung besteht.
     */
    public static function validAccessTokenForUser(PDO $pdo, int $userId): ?string {
        $stmt = $pdo->prepare(
            'SELECT gcal_access_token, gcal_refresh_token, gcal_token_expires_at
             FROM ' . PREFIX . '_user_settings WHERE user_id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['gcal_refresh_token'])) {
            return null;
        }

        $expiresAt = $row['gcal_token_expires_at'] ? strtotime((string)$row['gcal_token_expires_at']) : 0;
        $stillValid = !empty($row['gcal_access_token']) && $expiresAt > (time() + 60);
        if ($stillValid) {
            return (string)$row['gcal_access_token'];
        }

        // Refresh
        $fresh = self::refreshAccessToken((string)$row['gcal_refresh_token']);
        if ($fresh['access_token'] === '') {
            return null;
        }
        self::storeAccessToken($pdo, $userId, $fresh['access_token'], $fresh['expires_in']);
        return $fresh['access_token'];
    }

    /** Speichert Tokens nach erfolgreichem Code-Exchange. */
    public static function storeTokens(PDO $pdo, int $userId, string $accessToken, ?string $refreshToken, int $expiresIn): void {
        $pdo->prepare('INSERT IGNORE INTO ' . PREFIX . '_user_settings (user_id) VALUES (?)')->execute([$userId]);
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
        if ($refreshToken !== null && $refreshToken !== '') {
            $stmt = $pdo->prepare(
                'UPDATE ' . PREFIX . '_user_settings
                 SET gcal_access_token = ?, gcal_refresh_token = ?, gcal_token_expires_at = ?, gcal_enabled = 1
                 WHERE user_id = ?'
            );
            $stmt->execute([$accessToken, $refreshToken, $expiresAt, $userId]);
        } else {
            // Re-Auth ohne neues Refresh-Token: nur Access-Token aktualisieren
            $stmt = $pdo->prepare(
                'UPDATE ' . PREFIX . '_user_settings
                 SET gcal_access_token = ?, gcal_token_expires_at = ?, gcal_enabled = 1
                 WHERE user_id = ?'
            );
            $stmt->execute([$accessToken, $expiresAt, $userId]);
        }
    }

    private static function storeAccessToken(PDO $pdo, int $userId, string $accessToken, int $expiresIn): void {
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
        $stmt = $pdo->prepare(
            'UPDATE ' . PREFIX . '_user_settings SET gcal_access_token = ?, gcal_token_expires_at = ? WHERE user_id = ?'
        );
        $stmt->execute([$accessToken, $expiresAt, $userId]);
    }

    /** Entfernt die Verbindung (Tokens löschen). */
    public static function disconnect(PDO $pdo, int $userId): void {
        $stmt = $pdo->prepare(
            'UPDATE ' . PREFIX . '_user_settings
             SET gcal_access_token = NULL, gcal_refresh_token = NULL, gcal_token_expires_at = NULL, gcal_enabled = 0
             WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
    }

    /**
     * Legt ein Kalender-Event an und gibt die Google-Event-ID zurück.
     * @param array{summary:string, description?:string, date:string} $event  (date = YYYY-MM-DD, ganztägig)
     */
    public static function createAllDayEvent(string $accessToken, string $calendarId, array $event): ?string {
        $calId = $calendarId !== '' ? $calendarId : 'primary';
        $payload = [
            'summary' => $event['summary'],
            'description' => $event['description'] ?? '',
            'start' => ['date' => $event['date']],
            'end' => ['date' => $event['date']],
        ];
        $resp = self::postJson(
            self::CALENDAR_API . '/calendars/' . rawurlencode($calId) . '/events',
            $payload,
            $accessToken
        );
        return isset($resp['id']) ? (string)$resp['id'] : null;
    }

    // ---- HTTP-Helfer ---------------------------------------------------------

    /** @return array<string,mixed> */
    private static function postForm(string $url, array $fields): array {
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
            throw new \RuntimeException('Google OAuth HTTP error: ' . $err);
        }
        $decoded = json_decode((string)$body, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string,mixed> */
    private static function postJson(string $url, array $payload, string $accessToken): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken,
            ],
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException('Google Calendar HTTP error: ' . $err);
        }
        $decoded = json_decode((string)$body, true);
        return is_array($decoded) ? $decoded : [];
    }
}
