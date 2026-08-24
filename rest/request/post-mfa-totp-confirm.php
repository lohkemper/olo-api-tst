<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /auth/mfa-totp-confirm { code } — schließt das TOTP-Enrollment ab.
 *
 * Prüft den Code gegen das unbestätigte Secret; bei Erfolg wird die Methode
 * bestätigt. Hat der User noch keine Backup-Codes (erste MFA-Methode), werden
 * 10 erzeugt und EINMALIG im Klartext zurückgegeben.
 */
class requestPostMfaTotpConfirm extends RequestBase {
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

            $code = (string)($this->data['code'] ?? '');
            if ($code === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Bad Request', 'message' => 'Code is required']);
                return;
            }

            $stmt = $this->pdo->prepare('
                SELECT secret_encrypted, last_time_step FROM ' . PREFIX . '_user_totp
                WHERE user_id = :userId LIMIT 1
            ');
            $stmt->execute(['userId' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                http_response_code(409);
                echo json_encode(['error' => 'Conflict', 'message' => 'No pending TOTP setup']);
                return;
            }

            $secret = CryptoHelper::decrypt((string)$row['secret_encrypted']);
            $step = Totp::verify($secret, $code, $row['last_time_step'] !== null ? (int)$row['last_time_step'] : null);
            if ($step === null) {
                Logger::logSecurityEvent('TOTP enrollment - invalid code', ['user_id' => $userId]);
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized', 'message' => 'Invalid code']);
                return;
            }

            $this->pdo->prepare('UPDATE ' . PREFIX . '_user_totp SET last_time_step = ? WHERE user_id = ?')
                ->execute([$step, $userId]);
            MfaHelper::upsertMethod($this->pdo, $userId, 'totp', 'Authenticator-App', true);

            $response = ['success' => true];
            if (MfaHelper::backupCodesRemaining($this->pdo, $userId) === 0) {
                $response['backupCodes'] = MfaHelper::regenerateBackupCodes($this->pdo, $userId);
            }

            Logger::info('TOTP enrolled', ['user_id' => $userId]);
            echo json_encode($response);
        } catch (\Throwable $e) {
            $this->handleError('Error confirming TOTP setup', $e);
        }
    }
}
