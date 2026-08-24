<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * MFA-E-Mail-Einmalcodes — DB-gestützt (bewusst NICHT Session: attempts und
 * Resend-Cooldown dürfen nicht durch Wegwerfen des Session-Cookies resetbar
 * sein). Ein aktiver Code pro (user, purpose); Resend ersetzt atomar.
 *
 * Hash: hash_hmac(sha256, code, JWT_SECRET) — konstante Zeit; bcrypt bringt
 * bei 10^6-Suchraum + hartem 5-Versuche-Limit keinen Zusatznutzen.
 */
final class MfaEmailCode {
    public const TTL = 600;            // 10 min
    public const RESEND_COOLDOWN = 60; // Sekunden
    public const MAX_ATTEMPTS = 5;

    /**
     * Erzeugt + persistiert einen Code und gibt den Klartext (nur zum Mailen)
     * zurück. Bei aktivem Cooldown: null + $retryAfter gesetzt.
     */
    public static function issue(PDO $pdo, int $userId, string $purpose, ?int &$retryAfter = null): ?string {
        $stmt = $pdo->prepare('
            SELECT last_sent_at FROM ' . PREFIX . '_user_mfa_email_codes
            WHERE user_id = :userId AND purpose = :purpose
            LIMIT 1
        ');
        $stmt->execute(['userId' => $userId, 'purpose' => $purpose]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $elapsed = time() - strtotime((string)$row['last_sent_at']);
            if ($elapsed < self::RESEND_COOLDOWN) {
                $retryAfter = self::RESEND_COOLDOWN - $elapsed;
                return null;
            }
        }

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $pdo->prepare('
            INSERT INTO ' . PREFIX . '_user_mfa_email_codes
                (user_id, purpose, code_hash, expires_at, attempts, last_sent_at)
            VALUES (:userId, :purpose, :hash, FROM_UNIXTIME(:expires), 0, NOW())
            ON DUPLICATE KEY UPDATE
                code_hash = VALUES(code_hash),
                expires_at = VALUES(expires_at),
                attempts = 0,
                last_sent_at = NOW()
        ')->execute([
            'userId' => $userId,
            'purpose' => $purpose,
            'hash' => self::hashCode($code),
            'expires' => time() + self::TTL,
        ]);
        return $code;
    }

    /**
     * Konstantzeit-Verify: attempts++, hash_equals; Row wird bei Erfolg,
     * Versuchslimit oder Ablauf gelöscht (One-Time).
     */
    public static function verify(PDO $pdo, int $userId, string $purpose, string $code): bool {
        $code = preg_replace('/\s+/', '', $code);

        $stmt = $pdo->prepare('
            SELECT user_mfa_email_codes_id, code_hash, expires_at, attempts
            FROM ' . PREFIX . '_user_mfa_email_codes
            WHERE user_id = :userId AND purpose = :purpose
            LIMIT 1
        ');
        $stmt->execute(['userId' => $userId, 'purpose' => $purpose]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            // Timing-Uniformität: Dummy-Vergleich auch ohne aktiven Code.
            hash_equals(self::hashCode('000000'), self::hashCode($code === '' ? '1' : $code));
            return false;
        }

        $id = (int)$row['user_mfa_email_codes_id'];
        $expired = strtotime((string)$row['expires_at']) <= time();
        $exhausted = (int)$row['attempts'] >= self::MAX_ATTEMPTS;
        if ($expired || $exhausted) {
            self::delete($pdo, $id);
            hash_equals(self::hashCode('000000'), self::hashCode($code === '' ? '1' : $code));
            return false;
        }

        $pdo->prepare('UPDATE ' . PREFIX . '_user_mfa_email_codes SET attempts = attempts + 1 WHERE user_mfa_email_codes_id = ?')
            ->execute([$id]);

        $ok = $code !== '' && hash_equals((string)$row['code_hash'], self::hashCode($code));
        if ($ok) {
            self::delete($pdo, $id);
        } elseif ((int)$row['attempts'] + 1 >= self::MAX_ATTEMPTS) {
            self::delete($pdo, $id);
            Logger::logSecurityEvent('MFA email code invalidated - attempt limit', ['user_id' => $userId, 'purpose' => $purpose]);
        }
        return $ok;
    }

    private static function hashCode(string $code): string {
        return hash_hmac('sha256', $code, (string)$_ENV['JWT_SECRET']);
    }

    private static function delete(PDO $pdo, int $id): void {
        $pdo->prepare('DELETE FROM ' . PREFIX . '_user_mfa_email_codes WHERE user_mfa_email_codes_id = ?')
            ->execute([$id]);
    }
}
