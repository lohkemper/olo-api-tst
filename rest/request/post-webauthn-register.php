<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /auth/webauthn-register { label, credential } — prüft die Attestation
 * und speichert den Passkey (eingeloggt). Aktiviert die Registry-Methode
 * 'webauthn'; erste MFA-Methode → Backup-Codes einmalig zurückgeben.
 */
class requestPostWebauthnRegister extends RequestBase {
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

            if (!WebAuthnHelper::isConfigured() || !WebAuthnHelper::originAllowed()) {
                http_response_code(503);
                echo json_encode(['error' => 'WebAuthn is not configured on the server']);
                return;
            }

            $challenge = WebAuthnHelper::consumeChallenge('create');
            if ($challenge === null) {
                http_response_code(410);
                echo json_encode(['error' => 'Gone', 'message' => 'Challenge expired. Please try again.']);
                return;
            }

            $credential = $this->data['credential'] ?? [];
            $response = is_array($credential) ? ($credential['response'] ?? []) : [];
            $clientDataJSON = WebAuthnHelper::b64urlDecode((string)($response['clientDataJSON'] ?? ''));
            $attestationObject = WebAuthnHelper::b64urlDecode((string)($response['attestationObject'] ?? ''));
            if ($clientDataJSON === '' || $attestationObject === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Bad Request', 'message' => 'Malformed credential payload']);
                return;
            }

            $result = WebAuthnHelper::make()->processCreate(
                $clientDataJSON,
                $attestationObject,
                $challenge,
                false, // userVerification: preferred
                true
            );

            $credentialIdB64 = WebAuthnHelper::b64urlEncode((string)$result->credentialId);
            $transports = $response['transports'] ?? null;
            $label = trim((string)($this->data['label'] ?? '')) ?: 'Passkey';
            $label = mb_substr($label, 0, 100);

            try {
                $this->pdo->prepare('
                    INSERT INTO ' . PREFIX . '_user_webauthn_credentials
                        (user_id, credential_id, public_key, sign_count, transports, label)
                    VALUES (:userId, :credId, :publicKey, :signCount, :transports, :label)
                ')->execute([
                    'userId' => $userId,
                    'credId' => $credentialIdB64,
                    'publicKey' => (string)$result->credentialPublicKey,
                    'signCount' => (int)($result->signatureCounter ?? 0),
                    'transports' => is_array($transports) ? implode(',', array_map('strval', $transports)) : null,
                    'label' => $label,
                ]);
            } catch (\PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    http_response_code(409);
                    echo json_encode(['error' => 'Conflict', 'message' => 'This authenticator is already registered']);
                    return;
                }
                throw $e;
            }

            MfaHelper::upsertMethod($this->pdo, $userId, 'webauthn', 'Passkeys', true);

            $responseBody = [
                'credential' => [
                    'id' => (int)$this->pdo->lastInsertId(),
                    'label' => $label,
                    'createdAt' => date('Y-m-d H:i:s'),
                    'lastUsedAt' => null,
                    'transports' => is_array($transports) ? $transports : [],
                ],
            ];
            if (MfaHelper::backupCodesRemaining($this->pdo, $userId) === 0) {
                $responseBody['backupCodes'] = MfaHelper::regenerateBackupCodes($this->pdo, $userId);
            }

            Logger::info('WebAuthn credential registered', ['user_id' => $userId, 'label' => $label]);
            http_response_code(201);
            echo json_encode($responseBody);
        } catch (\lbuchs\WebAuthn\WebAuthnException $e) {
            Logger::logSecurityEvent('WebAuthn registration rejected', ['error' => $e->getMessage()]);
            http_response_code(400);
            echo json_encode(['error' => 'Bad Request', 'message' => 'Credential validation failed']);
        } catch (\Throwable $e) {
            $this->handleError('Error registering WebAuthn credential', $e);
        }
    }
}
