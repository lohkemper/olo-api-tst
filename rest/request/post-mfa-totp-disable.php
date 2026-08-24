<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /auth/mfa-totp-disable { code } — entfernt die TOTP-Methode.
 *
 * Erfordert einen gültigen TOTP- ODER Backup-Code (Schutz: eine gekaperte
 * Browser-Session allein kann MFA nicht abschalten). War TOTP die letzte
 * bestätigte Methode, räumt cleanupIfDisabled Backup-Codes + Trusted Devices ab.
 */
class requestPostMfaTotpDisable extends RequestBase {
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
                echo json_encode(['error' => 'Conflict', 'message' => 'TOTP is not enabled']);
                return;
            }

            $secret = CryptoHelper::decrypt((string)$row['secret_encrypted']);
            $lastStep = $row['last_time_step'] !== null ? (int)$row['last_time_step'] : null;
            $valid = Totp::verify($secret, $code, $lastStep) !== null
                || MfaHelper::consumeBackupCode($this->pdo, $userId, $code);
            if (!$valid) {
                Logger::logSecurityEvent('TOTP disable - invalid code', ['user_id' => $userId]);
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized', 'message' => 'Invalid code']);
                return;
            }

            $this->pdo->prepare('DELETE FROM ' . PREFIX . '_user_totp WHERE user_id = ?')->execute([$userId]);
            MfaHelper::removeMethod($this->pdo, $userId, 'totp');

            Logger::logSecurityEvent('TOTP disabled', ['user_id' => $userId]);
            echo json_encode(['success' => true]);
        } catch (\Throwable $e) {
            $this->handleError('Error disabling TOTP', $e);
        }
    }
}
