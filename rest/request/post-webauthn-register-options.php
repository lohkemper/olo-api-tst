<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /auth/webauthn-register-options — Create-Options fürs Passkey-Enrollment
 * (eingeloggt, Settings-Sicherheits-Tab). Challenge landet One-Time in der
 * Session; bereits registrierte Credentials wandern in die Exclude-List.
 */
class requestPostWebauthnRegisterOptions extends RequestBase {
    private array $data = [];

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $rateLimiter = new RateLimiter($this->pdo);
            $rateLimiter->requireLimit('mfa-enroll', 10, 300);

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            if (!WebAuthnHelper::isConfigured()) {
                http_response_code(503);
                echo json_encode(['error' => 'WebAuthn is not configured on the server']);
                return;
            }

            $stmt = $this->pdo->prepare('
                SELECT credential_id FROM ' . PREFIX . '_user_webauthn_credentials WHERE user_id = ?
            ');
            $stmt->execute([$userId]);
            $excludeIds = array_map(
                static fn(array $row) => WebAuthnHelper::b64urlDecode((string)$row['credential_id']),
                $stmt->fetchAll(PDO::FETCH_ASSOC)
            );

            $webauthn = WebAuthnHelper::make();
            $args = $webauthn->getCreateArgs(
                (string)$userId,
                (string)$user['username'],
                trim(((string)($user['first_name'] ?? '')) . ' ' . ((string)($user['last_name'] ?? ''))) ?: (string)$user['username'],
                60,
                false,          // residentKey: preferred (zweiter Faktor, kein passwortloses Login)
                'preferred',    // userVerification
                null,
                $excludeIds
            );
            WebAuthnHelper::storeChallenge($webauthn->getChallenge(), 'create');

            echo json_encode($args->publicKey);
        } catch (\Throwable $e) {
            $this->handleError('Error building WebAuthn register options', $e);
        }
    }
}
