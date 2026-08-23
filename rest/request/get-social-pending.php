<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /auth/social-pending — liefert den ausstehenden Social-Login-Schritt.
 *
 * Wird von den SPA-Seiten /login/link und /register/complete beim Laden
 * abgefragt. Liefert die Anzeige-Daten plus das CSRF-Token für den folgenden
 * POST (social-link / social-complete). 404, wenn nichts aussteht oder die
 * TTL (10 min) abgelaufen ist.
 */
class requestGetSocialPending extends RequestBase {
    private const PENDING_TTL = 600; // 10 min

    public function setRequest(array $request): void { /* keine Params */ }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }

            $pending = $_SESSION['social_pending'] ?? null;
            $expired = !is_array($pending)
                || (time() - (int)($pending['ts'] ?? 0)) >= self::PENDING_TTL;
            if ($expired) {
                unset($_SESSION['social_pending']);
                http_response_code(404);
                echo json_encode(['error' => 'Not Found', 'message' => 'No pending social login']);
                return;
            }

            $response = [
                'mode' => (string)$pending['mode'],
                'provider' => (string)$pending['provider'],
                'email' => (string)$pending['email'],
                'displayName' => (string)($pending['displayName'] ?? ''),
                // Bestehendes CSRF-Token wiederverwenden (kein Invalidieren anderer Tabs).
                'csrfToken' => CsrfHelper::getToken() ?? CsrfHelper::generateToken(),
            ];
            if ($pending['mode'] === 'complete') {
                $response['suggestedUsername'] = (string)($pending['suggestedUsername'] ?? '');
            }

            echo json_encode($response);
        } catch (\Throwable $e) {
            $this->handleError('Error reading pending social login', $e);
        }
    }
}
