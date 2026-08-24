<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /auth/mfa-backup-regenerate — erzeugt 10 neue Backup-Codes.
 *
 * Invalidiert alle alten Codes. Nur bei aktivem MFA. Die Klartext-Codes
 * werden ausschließlich in dieser Antwort angezeigt.
 */
class requestPostMfaBackupRegenerate extends RequestBase {
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

            if (!MfaHelper::confirmedMethods($this->pdo, $userId)) {
                http_response_code(409);
                echo json_encode(['error' => 'Conflict', 'message' => 'MFA is not enabled']);
                return;
            }

            $codes = MfaHelper::regenerateBackupCodes($this->pdo, $userId);
            Logger::info('MFA backup codes regenerated', ['user_id' => $userId]);
            echo json_encode(['backupCodes' => $codes]);
        } catch (\Throwable $e) {
            $this->handleError('Error regenerating backup codes', $e);
        }
    }
}
