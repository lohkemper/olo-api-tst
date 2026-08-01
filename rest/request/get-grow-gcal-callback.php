<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /grow/gcal-callback?code=...&state=... — OAuth-Redirect-Ziel von Google.
 *
 * Verifiziert den `state`, tauscht den Code gegen Tokens, speichert sie und
 * leitet den Browser zurück in die SPA (GOOGLE_OAUTH_SPA_RETURN_URL) mit
 * ?gcal=connected|error. Antwortet per Redirect (kein JSON).
 */
class requestGetGrowGcalCallback extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        $returnUrl = GoogleOAuth::spaReturnUrl();
        try {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }

            $user = $this->getCurrentUser();
            $code = isset($this->request['code']) ? (string)$this->request['code'] : '';
            $state = isset($this->request['state']) ? (string)$this->request['state'] : '';
            $sessionState = (string)($_SESSION['gcal_oauth_state'] ?? '');
            $sessionUser = (int)($_SESSION['gcal_oauth_user'] ?? 0);

            // Sicherheits-Checks: eingeloggt, state stimmt, code vorhanden
            if (!$user || $code === '' || $state === '' || !hash_equals($sessionState, $state)
                || $sessionUser !== (int)$user['users_id']) {
                $this->redirect($returnUrl, 'error');
                return;
            }

            unset($_SESSION['gcal_oauth_state'], $_SESSION['gcal_oauth_user']);

            $tokens = GoogleOAuth::exchangeCode($code);
            if ($tokens['access_token'] === '') {
                $this->redirect($returnUrl, 'error');
                return;
            }

            GoogleOAuth::storeTokens(
                $this->pdo,
                (int)$user['users_id'],
                $tokens['access_token'],
                $tokens['refresh_token'],
                $tokens['expires_in']
            );

            $this->redirect($returnUrl, 'connected');
        } catch (\Throwable $e) {
            $this->log(['gcal-callback error' => $e->getMessage()], 'error');
            $this->redirect($returnUrl, 'error');
        }
    }

    private function redirect(string $base, string $status): void {
        if ($base === '') {
            // Kein Return-URL konfiguriert → minimale Textantwort
            http_response_code($status === 'connected' ? 200 : 400);
            echo $status === 'connected' ? 'Google Calendar connected. You can close this window.' : 'Google connection failed.';
            return;
        }
        $sep = strpos($base, '?') !== false ? '&' : '?';
        header('Location: ' . $base . $sep . 'gcal=' . $status, true, 302);
    }
}
