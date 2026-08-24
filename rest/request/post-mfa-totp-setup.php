<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /auth/mfa-totp-setup — startet das TOTP-Enrollment (eingeloggt).
 *
 * Erzeugt ein neues Secret (verschlüsselt at rest), legt die Registry-Zeile
 * unbestätigt an und liefert Secret (Base32) + otpauth-URL — den QR-Code
 * rendert das Frontend. Erneuter Aufruf vor dem Confirm überschreibt
 * (Abbruch/Retry). CSRF zentral erzwungen (nicht exempt).
 */
class requestPostMfaTotpSetup extends RequestBase {
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

            if (!CryptoHelper::isConfigured()) {
                http_response_code(503);
                echo json_encode(['error' => 'MFA is not configured on the server']);
                return;
            }

            // Bereits bestätigtes TOTP nicht stillschweigend überschreiben.
            $confirmed = array_column(MfaHelper::confirmedMethods($this->pdo, $userId), 'type');
            if (in_array('totp', $confirmed, true)) {
                http_response_code(409);
                echo json_encode(['error' => 'Conflict', 'message' => 'TOTP is already enabled']);
                return;
            }

            $secret = Totp::generateSecret();
            $this->pdo->prepare('
                INSERT INTO ' . PREFIX . '_user_totp (user_id, secret_encrypted, last_time_step)
                VALUES (:userId, :secret, NULL)
                ON DUPLICATE KEY UPDATE secret_encrypted = VALUES(secret_encrypted), last_time_step = NULL
            ')->execute(['userId' => $userId, 'secret' => CryptoHelper::encrypt($secret)]);

            MfaHelper::upsertMethod($this->pdo, $userId, 'totp', 'Authenticator-App', false);

            $secretB32 = Totp::base32Encode($secret);
            echo json_encode([
                'secret' => $secretB32,
                'otpauthUrl' => Totp::otpauthUrl($secretB32, (string)$user['email']),
            ]);
        } catch (\Throwable $e) {
            $this->handleError('Error starting TOTP setup', $e);
        }
    }
}
