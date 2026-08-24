<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Symmetrische At-Rest-Verschlüsselung für Server-Secrets (z.B. TOTP-Seeds).
 *
 * sodium_crypto_secretbox (XSalsa20-Poly1305): Nonce + MAC integriert,
 * misuse-resistant. Gespeichert wird base64(nonce || ciphertext).
 *
 * Key: .env APP_ENCRYPTION_KEY = base64 von 32 zufälligen Bytes, z.B. erzeugt mit
 *   php -r "echo base64_encode(random_bytes(32));"
 * Bewusst NICHT in der Dotenv-required-Liste (cfg.php) — fehlt der Key, stirbt
 * nicht die ganze API, sondern nur der erste Gebrauch hier mit klarem 500.
 */
final class CryptoHelper {

    public static function isConfigured(): bool {
        return self::keyOrNull() !== null;
    }

    public static function encrypt(string $plain): string {
        $key = self::requireKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
        return base64_encode($nonce . $cipher);
    }

    public static function decrypt(string $blob): string {
        $key = self::requireKey();
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('CryptoHelper: malformed ciphertext blob');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        if ($plain === false) {
            throw new \RuntimeException('CryptoHelper: decryption failed (wrong key or tampered data)');
        }
        return $plain;
    }

    private static function keyOrNull(): ?string {
        $b64 = (string)($_ENV['APP_ENCRYPTION_KEY'] ?? '');
        if ($b64 === '') return null;
        $key = base64_decode($b64, true);
        return ($key !== false && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) ? $key : null;
    }

    private static function requireKey(): string {
        $key = self::keyOrNull();
        if ($key === null) {
            Logger::logSecurityEvent('APP_ENCRYPTION_KEY missing or invalid (needs base64 of 32 bytes)');
            throw new \RuntimeException('Server encryption key is not configured');
        }
        return $key;
    }
}
