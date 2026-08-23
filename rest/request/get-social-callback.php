<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /auth/google-callback bzw. /auth/facebook-callback — OAuth-Redirect-Ziel.
 *
 * Verifiziert den `state` (Session, TTL 10 min), tauscht den Code, holt das
 * Profil und verzweigt in drei Fälle:
 *   (a) Identity bekannt      → Session ausstellen, 302 <return>/
 *   (b) E-Mail existiert lokal → Pending mode=link,     302 <return>/login/link
 *   (c) unbekannt             → Pending mode=complete,  302 <return>/register/complete
 * Fehler → 302 <return>/login?social=error&reason=…  Antwortet nur per Redirect.
 */
class requestGetSocialCallback extends RequestBase {
    private const PENDING_TTL = 600; // 10 min

    private array $request = [];
    private string $provider = '';

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function setProvider(string $provider): void {
        $this->provider = $provider;
    }

    public function execute(): void {
        // Return-Basis so früh wie möglich bestimmen (Session kann fehlen).
        $returnBase = SocialLoginProvider::resolveReturnBase('');
        try {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }

            $oauth = $_SESSION['social_oauth'] ?? null;
            if (is_array($oauth) && !empty($oauth['return'])) {
                $returnBase = (string)$oauth['return'];
            }

            $rateLimiter = new RateLimiter($this->pdo);
            $rateLimiter->requireLimit('social-callback', 10, 300);

            $provider = SocialLoginProvider::for($this->provider);
            if (!$provider->isConfigured()) {
                $this->redirectError($returnBase, 'config');
                return;
            }

            // Nutzer hat den Consent abgebrochen (z.B. error=access_denied).
            if (!empty($this->request['error'])) {
                unset($_SESSION['social_oauth']);
                $this->redirectError($returnBase, 'denied');
                return;
            }

            $code = (string)($this->request['code'] ?? '');
            $state = (string)($this->request['state'] ?? '');
            $sessionState = is_array($oauth) ? (string)($oauth['state'] ?? '') : '';
            $stateOk = $code !== '' && $state !== '' && $sessionState !== ''
                && hash_equals($sessionState, $state)
                && ($oauth['provider'] ?? '') === $this->provider
                && (time() - (int)($oauth['ts'] ?? 0)) < self::PENDING_TTL;

            if (!$stateOk) {
                Logger::logSecurityEvent('Social login state validation failed', ['provider' => $this->provider]);
                $this->redirectError($returnBase, 'state');
                return;
            }
            unset($_SESSION['social_oauth']);

            $profile = $provider->fetchProfile($code);
            if ($profile['providerUserId'] === '') {
                $this->redirectError($returnBase, 'unknown');
                return;
            }
            if ($profile['email'] === '') {
                $this->redirectError($returnBase, 'no-email');
                return;
            }

            // Fall (a): Identity bereits verknüpft → direkt einloggen.
            $user = $this->findUserByIdentity($this->provider, $profile['providerUserId']);
            if ($user) {
                if (!(int)($user['is_active'] ?? 1)) {
                    $this->redirectError($returnBase, 'disabled');
                    return;
                }
                $this->pdo->prepare('UPDATE ' . PREFIX . '_users SET last_login = NOW() WHERE users_id = ?')
                    ->execute([(int)$user['users_id']]);
                JwtSession::issue($this->pdo, $user);
                Logger::info('Social login', [
                    'user_id' => (int)$user['users_id'],
                    'provider' => $this->provider,
                ]);
                $this->redirectTo($returnBase . '/');
                return;
            }

            // Fall (b): E-Mail existiert lokal → Verknüpfung nach Passwort-Bestätigung.
            $existing = $this->findUserByEmail($profile['email']);
            if ($existing) {
                if (!(int)($existing['is_active'] ?? 1)) {
                    $this->redirectError($returnBase, 'disabled');
                    return;
                }
                if (empty($existing['password_hash'])) {
                    // Social-only-Konto eines anderen Providers: keine Passwort-Bestätigung möglich.
                    $this->redirectError($returnBase, 'no-password-account');
                    return;
                }
                $_SESSION['social_pending'] = [
                    'mode' => 'link',
                    'userId' => (int)$existing['users_id'],
                    'ts' => time(),
                ] + $this->pendingProfile($profile);
                $this->redirectTo($returnBase . '/login/link');
                return;
            }

            // Fall (c): unbekannt → Registrierung vervollständigen.
            $_SESSION['social_pending'] = [
                'mode' => 'complete',
                'suggestedUsername' => $this->suggestUsername($profile),
                'ts' => time(),
            ] + $this->pendingProfile($profile);
            $this->redirectTo($returnBase . '/register/complete');

        } catch (\Throwable $e) {
            $this->log(['social-callback error' => $e->getMessage()], 'error');
            $this->redirectError($returnBase, 'unknown');
        }
    }

    private function pendingProfile(array $profile): array {
        return [
            'provider' => $this->provider,
            'providerUserId' => $profile['providerUserId'],
            'email' => $profile['email'],
            'displayName' => $profile['displayName'],
            'firstName' => $profile['firstName'],
            'lastName' => $profile['lastName'],
            'avatarUrl' => $profile['avatarUrl'],
        ];
    }

    private function findUserByIdentity(string $provider, string $providerUserId): ?array {
        $stmt = $this->pdo->prepare('
            SELECT u.users_id, u.email, u.username, u.first_name, u.last_name,
                   u.is_active, u.theme, u.density, u.accent
            FROM ' . PREFIX . '_user_identities i
            INNER JOIN ' . PREFIX . '_users u ON u.users_id = i.user_id
            WHERE i.provider = :provider AND i.provider_user_id = :pid
            LIMIT 1
        ');
        $stmt->execute(['provider' => $provider, 'pid' => $providerUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function findUserByEmail(string $email): ?array {
        $stmt = $this->pdo->prepare('
            SELECT users_id, email, username, password_hash, is_active
            FROM ' . PREFIX . '_users
            WHERE email = :email
            LIMIT 1
        ');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Vorschlag fürs Username-Feld (nur Prefill, Eindeutigkeit prüft social-complete). */
    private function suggestUsername(array $profile): string {
        $base = $profile['displayName'] !== ''
            ? $profile['displayName']
            : (string)strstr($profile['email'], '@', true);
        $slug = strtolower(trim((string)preg_replace('/[^a-zA-Z0-9_-]+/', '-', $base), '-'));
        $slug = substr($slug, 0, 50);
        return strlen($slug) >= 3 ? $slug : '';
    }

    private function redirectError(string $base, string $reason): void {
        $this->redirectTo($base !== '' ? $base . '/login?social=error&reason=' . $reason : '', $reason);
    }

    private function redirectTo(string $url, string $fallbackReason = ''): void {
        if ($url === '') {
            // Keine Return-URL konfiguriert → minimale Textantwort.
            http_response_code(400);
            echo 'Social login failed' . ($fallbackReason !== '' ? ' (' . $fallbackReason . ')' : '') . '.';
            return;
        }
        header('Location: ' . $url, true, 302);
    }
}
