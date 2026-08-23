<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /auth/social-url?provider=google|facebook — liefert die Provider-Consent-URL.
 *
 * Pre-Auth-Endpoint (Login-/Register-Seite). Erzeugt einen CSRF-`state`, merkt
 * ihn mit Provider + Return-Ziel (Origin gegen Whitelist gematcht) in der
 * Session und gibt { url } zurück. Das Frontend leitet den Browser dorthin.
 */
class requestGetSocialUrl extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $rateLimiter = new RateLimiter($this->pdo);
            $rateLimiter->requireLimit('social-url', 10, 300);

            $providerName = (string)($this->request['provider'] ?? '');
            if (!in_array($providerName, SocialLoginProvider::PROVIDERS, true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Bad Request', 'message' => 'Unknown provider']);
                return;
            }

            $provider = SocialLoginProvider::for($providerName);
            if (!$provider->isConfigured()) {
                http_response_code(503);
                echo json_encode(['error' => ucfirst($providerName) . ' login is not configured on the server']);
                return;
            }

            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }

            // Return-Ziel aus dem Origin des SPA-Requests (Whitelist, sonst Prod-Default).
            $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
            if ($origin === '' && !empty($_SERVER['HTTP_REFERER'])) {
                $ref = parse_url((string)$_SERVER['HTTP_REFERER']);
                if (!empty($ref['scheme']) && !empty($ref['host'])) {
                    $origin = $ref['scheme'] . '://' . $ref['host'] . (isset($ref['port']) ? ':' . $ref['port'] : '');
                }
            }

            $state = bin2hex(random_bytes(16));
            $_SESSION['social_oauth'] = [
                'state' => $state,
                'provider' => $providerName,
                'return' => SocialLoginProvider::resolveReturnBase($origin),
                'ts' => time(),
            ];

            echo json_encode(['url' => $provider->buildAuthUrl($state)]);
        } catch (\Throwable $e) {
            $this->handleError('Error building social auth URL', $e);
        }
    }
}
