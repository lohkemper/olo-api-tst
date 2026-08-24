<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * MFA-Kernlogik: Methoden-Registry, Challenge-Session, Trusted Devices,
 * Backup-Codes. Methoden-neutral — TOTP/E-Mail/WebAuthn stecken nur ihre
 * Verify-Implementierung in post-mfa-verify.php dazu.
 *
 * Plan: docs/planning/mfa-2fa.md. MFA aktiv <=> confirmedMethods() nicht leer.
 */
final class MfaHelper {
    public const CHALLENGE_TTL = 600;              // 10 min
    public const MAX_ATTEMPTS = 5;
    public const TRUST_COOKIE = 'mfa_trust';
    public const TRUST_DAYS = 30;
    public const BACKUP_CODE_COUNT = 10;
    // Alphabet ohne Verwechsler (kein 0/O/1/I/L)
    private const BACKUP_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    // ---- Registry ------------------------------------------------------------

    /** Bestätigte Methoden des Users: [['type' => 'totp', 'label' => …], …] */
    public static function confirmedMethods(PDO $pdo, int $userId): array {
        $stmt = $pdo->prepare('
            SELECT method, label FROM ' . PREFIX . '_user_mfa_methods
            WHERE user_id = :userId AND is_confirmed = 1
            ORDER BY confirmed_at ASC
        ');
        $stmt->execute(['userId' => $userId]);
        return array_map(
            static fn(array $row) => ['type' => $row['method'], 'label' => $row['label'] ?? null],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public static function upsertMethod(PDO $pdo, int $userId, string $method, ?string $label, bool $confirmed): void {
        $pdo->prepare('
            INSERT INTO ' . PREFIX . '_user_mfa_methods (user_id, method, label, is_confirmed, confirmed_at)
            VALUES (:userId, :method, :label, :confirmed, IF(:confirmed2, NOW(), NULL))
            ON DUPLICATE KEY UPDATE
              label = VALUES(label),
              is_confirmed = VALUES(is_confirmed),
              confirmed_at = IF(VALUES(is_confirmed) = 1, NOW(), NULL)
        ')->execute([
            'userId' => $userId, 'method' => $method, 'label' => $label,
            'confirmed' => (int)$confirmed, 'confirmed2' => (int)$confirmed,
        ]);
    }

    public static function removeMethod(PDO $pdo, int $userId, string $method): void {
        $pdo->prepare('DELETE FROM ' . PREFIX . '_user_mfa_methods WHERE user_id = ? AND method = ?')
            ->execute([$userId, $method]);
        self::cleanupIfDisabled($pdo, $userId);
    }

    public static function touchMethod(PDO $pdo, int $userId, string $method): void {
        $pdo->prepare('UPDATE ' . PREFIX . '_user_mfa_methods SET last_used_at = NOW() WHERE user_id = ? AND method = ?')
            ->execute([$userId, $method]);
    }

    /** Letzte bestätigte Methode entfernt → Backup-Codes + Trusted Devices löschen. */
    public static function cleanupIfDisabled(PDO $pdo, int $userId): void {
        if (self::confirmedMethods($pdo, $userId)) {
            return;
        }
        $pdo->prepare('DELETE FROM ' . PREFIX . '_user_mfa_backup_codes WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM ' . PREFIX . '_user_trusted_devices WHERE user_id = ?')->execute([$userId]);
        Logger::info('MFA fully disabled - backup codes and trusted devices removed', ['user_id' => $userId]);
    }

    // ---- Challenge-Session ---------------------------------------------------

    public static function beginChallenge(PDO $pdo, int $userId, array $methods, string $origin): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['mfa_pending'] = [
            'userId' => $userId,
            'methods' => $methods,
            'backup' => self::backupCodesRemaining($pdo, $userId) > 0,
            'origin' => $origin,
            'attempts' => 0,
            'ts' => time(),
        ];
    }

    /** Gültiges Pending oder null (abgelaufene werden entfernt). */
    public static function pending(): ?array {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $pending = $_SESSION['mfa_pending'] ?? null;
        if (!is_array($pending) || (time() - (int)($pending['ts'] ?? 0)) >= self::CHALLENGE_TTL) {
            unset($_SESSION['mfa_pending']);
            return null;
        }
        return $pending;
    }

    public static function clearPending(): void {
        unset($_SESSION['mfa_pending']);
    }

    // ---- Trusted Devices -----------------------------------------------------

    public static function isTrustedDevice(PDO $pdo, int $userId): bool {
        $token = (string)($_COOKIE[self::TRUST_COOKIE] ?? '');
        if ($token === '') {
            return false;
        }
        $hash = hash('sha256', $token);
        $stmt = $pdo->prepare('
            SELECT trusted_devices_id FROM ' . PREFIX . '_user_trusted_devices
            WHERE user_id = :userId AND token_hash = :hash AND expires_at > NOW()
            LIMIT 1
        ');
        $stmt->execute(['userId' => $userId, 'hash' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $pdo->prepare('UPDATE ' . PREFIX . '_user_trusted_devices SET last_used_at = NOW() WHERE trusted_devices_id = ?')
            ->execute([(int)$row['trusted_devices_id']]);
        return true;
    }

    public static function issueTrustCookie(PDO $pdo, int $userId): void {
        $token = bin2hex(random_bytes(32));
        $expiresAt = time() + self::TRUST_DAYS * 86400;

        $pdo->prepare('
            INSERT INTO ' . PREFIX . '_user_trusted_devices (user_id, token_hash, label, expires_at)
            VALUES (:userId, :hash, :label, FROM_UNIXTIME(:expires))
        ')->execute([
            'userId' => $userId,
            'hash' => hash('sha256', $token),
            'label' => self::deviceLabel(),
            'expires' => $expiresAt,
        ]);

        $isSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
                    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        setcookie(self::TRUST_COOKIE, $token, [
            'expires' => $expiresAt,
            'path' => '/',
            'domain' => '',
            'secure' => $isSecure,
            'httponly' => true,
            'samesite' => $isSecure ? 'None' : 'Lax',
        ]);
    }

    /** Grobes Geräte-Label aus dem User-Agent ("Chrome · Windows"). */
    private static function deviceLabel(): string {
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $browser = 'Browser';
        foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari'] as $needle => $name) {
            if (strpos($ua, $needle) !== false) { $browser = $name; break; }
        }
        $os = 'Unbekannt';
        foreach (['Windows' => 'Windows', 'Android' => 'Android', 'iPhone' => 'iOS', 'iPad' => 'iPadOS', 'Mac OS' => 'macOS', 'Linux' => 'Linux'] as $needle => $name) {
            if (strpos($ua, $needle) !== false) { $os = $name; break; }
        }
        return $browser . ' · ' . $os;
    }

    // ---- Backup-Codes --------------------------------------------------------

    public static function backupCodesRemaining(PDO $pdo, int $userId): int {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM ' . PREFIX . '_user_mfa_backup_codes
            WHERE user_id = ? AND used_at IS NULL
        ');
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /** Löscht alte Codes, erzeugt 10 neue; Klartext-Liste nur EINMAL zurückgegeben. */
    public static function regenerateBackupCodes(PDO $pdo, int $userId): array {
        $pdo->prepare('DELETE FROM ' . PREFIX . '_user_mfa_backup_codes WHERE user_id = ?')->execute([$userId]);
        $insert = $pdo->prepare('
            INSERT INTO ' . PREFIX . '_user_mfa_backup_codes (user_id, code_hash) VALUES (:userId, :hash)
        ');
        $codes = [];
        for ($i = 0; $i < self::BACKUP_CODE_COUNT; $i++) {
            $raw = '';
            for ($j = 0; $j < 8; $j++) {
                $raw .= self::BACKUP_ALPHABET[random_int(0, strlen(self::BACKUP_ALPHABET) - 1)];
            }
            $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4);
            $insert->execute(['userId' => $userId, 'hash' => password_hash($raw, PASSWORD_BCRYPT)]);
        }
        return $codes;
    }

    /** Prüft einen Backup-Code (normalisiert) und markiert ihn als verbraucht. */
    public static function consumeBackupCode(PDO $pdo, int $userId, string $code): bool {
        $normalized = strtoupper(str_replace(['-', ' '], '', $code));
        $stmt = $pdo->prepare('
            SELECT backup_codes_id, code_hash FROM ' . PREFIX . '_user_mfa_backup_codes
            WHERE user_id = ? AND used_at IS NULL
        ');
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (password_verify($normalized, $row['code_hash'])) {
                $pdo->prepare('UPDATE ' . PREFIX . '_user_mfa_backup_codes SET used_at = NOW() WHERE backup_codes_id = ?')
                    ->execute([(int)$row['backup_codes_id']]);
                return true;
            }
        }
        return false;
    }

    // ---- User-Row für JwtSession::issue --------------------------------------

    public static function loadUserRow(PDO $pdo, int $userId): ?array {
        $stmt = $pdo->prepare('
            SELECT users_id, email, username, first_name, last_name,
                   is_active, theme, density, accent
            FROM ' . PREFIX . '_users
            WHERE users_id = :userId
            LIMIT 1
        ');
        $stmt->execute(['userId' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
