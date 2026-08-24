<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /auth/mfa-status — Datenquelle des Settings-Sicherheits-Tabs (eingeloggt).
 *
 * Liefert konfigurierten MFA-Zustand: Methoden, Backup-Code-Zähler,
 * Trusted-Device-Liste (current-Flag über den Cookie-Hash) und die
 * WebAuthn-RP-ID (für den FE-Dev-Guard).
 */
class requestGetMfaStatus extends RequestBase {
    public function setRequest(array $request): void { /* keine Params */ }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $stmt = $this->pdo->prepare('
                SELECT method, label, is_confirmed, confirmed_at, last_used_at
                FROM ' . PREFIX . '_user_mfa_methods
                WHERE user_id = :userId
                ORDER BY created_at ASC
            ');
            $stmt->execute(['userId' => $userId]);
            $methods = array_map(static fn(array $row) => [
                'type' => $row['method'],
                'label' => $row['label'],
                'confirmed' => (bool)$row['is_confirmed'],
                'confirmedAt' => $row['confirmed_at'],
                'lastUsedAt' => $row['last_used_at'],
            ], $stmt->fetchAll(PDO::FETCH_ASSOC));

            $backupStmt = $this->pdo->prepare('
                SELECT COUNT(*) AS remaining, MAX(created_at) AS generated_at
                FROM ' . PREFIX . '_user_mfa_backup_codes
                WHERE user_id = :userId AND used_at IS NULL
            ');
            $backupStmt->execute(['userId' => $userId]);
            $backup = $backupStmt->fetch(PDO::FETCH_ASSOC) ?: ['remaining' => 0, 'generated_at' => null];

            $currentHash = isset($_COOKIE[MfaHelper::TRUST_COOKIE])
                ? hash('sha256', (string)$_COOKIE[MfaHelper::TRUST_COOKIE])
                : '';
            $deviceStmt = $this->pdo->prepare('
                SELECT trusted_devices_id, token_hash, label, created_at, last_used_at, expires_at
                FROM ' . PREFIX . '_user_trusted_devices
                WHERE user_id = :userId AND expires_at > NOW()
                ORDER BY created_at DESC
            ');
            $deviceStmt->execute(['userId' => $userId]);
            $devices = array_map(static fn(array $row) => [
                'id' => (int)$row['trusted_devices_id'],
                'label' => $row['label'],
                'createdAt' => $row['created_at'],
                'lastUsedAt' => $row['last_used_at'],
                'expiresAt' => $row['expires_at'],
                'current' => $currentHash !== '' && hash_equals($row['token_hash'], $currentHash),
            ], $deviceStmt->fetchAll(PDO::FETCH_ASSOC));

            $confirmed = array_filter($methods, static fn(array $m) => $m['confirmed']);
            echo json_encode([
                'enabled' => count($confirmed) > 0,
                'methods' => $methods,
                'backupCodes' => [
                    'remaining' => (int)$backup['remaining'],
                    'generatedAt' => $backup['generated_at'],
                ],
                'trustedDevices' => $devices,
                'webauthn' => ['rpId' => (string)($_ENV['WEBAUTHN_RP_ID'] ?? '')],
            ]);
        } catch (\Throwable $e) {
            $this->handleError('Error loading MFA status', $e);
        }
    }
}
