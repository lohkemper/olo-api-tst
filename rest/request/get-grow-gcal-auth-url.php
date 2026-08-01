<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /grow/gcal-auth-url — liefert die Google-Consent-URL für den OAuth-Connect.
 *
 * Erzeugt einen CSRF-`state`, legt ihn in der Session ab und gibt { url } zurück.
 * Das Frontend leitet den Browser auf diese URL weiter.
 */
class requestGetGrowGcalAuthUrl extends RequestBase {
    public function setRequest(array $request): void { /* keine Params */ }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');
            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            if (!GoogleOAuth::isConfigured()) {
                http_response_code(503);
                echo json_encode(['error' => 'Google OAuth is not configured on the server']);
                return;
            }

            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            $state = bin2hex(random_bytes(16));
            $_SESSION['gcal_oauth_state'] = $state;
            $_SESSION['gcal_oauth_user'] = $userId;

            echo json_encode(['url' => GoogleOAuth::buildAuthUrl($state)]);
        } catch (\Throwable $e) {
            $this->handleError('Error building Google auth URL', $e);
        }
    }
}
