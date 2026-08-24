<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /auth/webauthn-verify-options — Get-Options für die Login-Challenge
 * (pre-auth, erfordert gültiges mfa_pending). Die eigentliche Assertion geht
 * an POST /auth/mfa-verify {method:'webauthn', assertion}.
 */
class requestPostWebauthnVerifyOptions extends RequestBase {
    private array $data = [];

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $pending = MfaHelper::pending();
            if ($pending === null) {
                http_response_code(409);
                echo json_encode(['error' => 'Conflict', 'message' => 'No pending MFA challenge']);
                return;
            }

            if (!WebAuthnHelper::isConfigured()) {
                http_response_code(503);
                echo json_encode(['error' => 'WebAuthn is not configured on the server']);
                return;
            }

            $stmt = $this->pdo->prepare('
                SELECT credential_id FROM ' . PREFIX . '_user_webauthn_credentials WHERE user_id = ?
            ');
            $stmt->execute([(int)$pending['userId']]);
            $credentialIds = array_map(
                static fn(array $row) => WebAuthnHelper::b64urlDecode((string)$row['credential_id']),
                $stmt->fetchAll(PDO::FETCH_ASSOC)
            );
            if (!$credentialIds) {
                http_response_code(404);
                echo json_encode(['error' => 'Not Found', 'message' => 'No passkeys registered']);
                return;
            }

            $webauthn = WebAuthnHelper::make();
            $args = $webauthn->getGetArgs($credentialIds, 60);
            WebAuthnHelper::storeChallenge($webauthn->getChallenge(), 'get');

            // rpId explizit mitliefern — das FE nutzt sie für den Dev-Origin-Guard.
            $publicKey = $args->publicKey;
            $publicKey->rpId = WebAuthnHelper::rpId();
            echo json_encode($publicKey);
        } catch (\Throwable $e) {
            $this->handleError('Error building WebAuthn verify options', $e);
        }
    }
}
