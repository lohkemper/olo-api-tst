<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /auth/mfa-email-confirm { code } — aktiviert die E-Mail-Methode
 * (eingeloggt). Der bestätigte Code beweist Zugriff aufs Konto-Postfach;
 * ein Secret at rest ist nicht nötig (Empfänger ist immer mbc_users.email).
 */
class requestPostMfaEmailConfirm extends RequestBase {
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
            if (!MfaEmailCode::verify($this->pdo, $userId, 'enroll', $code)) {
                Logger::logSecurityEvent('MFA email enrollment - invalid code', ['user_id' => $userId]);
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized', 'message' => 'Invalid or expired code']);
                return;
            }

            MfaHelper::upsertMethod($this->pdo, $userId, 'email', 'E-Mail-Code', true);

            $response = ['enabled' => true, 'method' => 'email'];
            if (MfaHelper::backupCodesRemaining($this->pdo, $userId) === 0) {
                // Erste MFA-Methode → Backup-Codes erzeugen (einmalige Anzeige).
                $response['backupCodes'] = MfaHelper::regenerateBackupCodes($this->pdo, $userId);
            }

            Logger::info('MFA email method enrolled', ['user_id' => $userId]);
            echo json_encode($response);
        } catch (\Throwable $e) {
            $this->handleError('Error confirming MFA email method', $e);
        }
    }
}
