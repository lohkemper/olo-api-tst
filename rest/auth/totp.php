<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * TOTP nach RFC 6238 (HMAC-SHA1, 6 Digits, 30s-Steps) + Base32 (RFC 4648).
 * Pure PHP, keine Abhängigkeiten. Plan: docs/planning/mfa-2fa.md.
 */
final class Totp {
    public const PERIOD = 30;
    public const DIGITS = 6;
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** 20 zufällige Bytes — Standard-Seed-Länge für SHA1-TOTP. */
    public static function generateSecret(): string {
        return random_bytes(20);
    }

    /**
     * Prüft einen Code im Fenster T-window..T+window und erzwingt
     * Step-Monotonie (Replay-Schutz): akzeptiert nur Steps > $lastStep.
     * @return int|null akzeptierter Zeitstep oder null
     */
    public static function verify(string $secret, string $code, ?int $lastStep, int $window = 1): ?int {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return null;
        }
        $now = intdiv(time(), self::PERIOD);
        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $now + $offset;
            if ($lastStep !== null && $step <= $lastStep) {
                continue; // bereits verbrauchter oder älterer Step
            }
            if (hash_equals(self::codeAt($secret, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    /** HOTP-Wert (RFC 4226 Dynamic Truncation) für einen Zeitstep. */
    public static function codeAt(string $secret, int $step): string {
        $counter = pack('N2', ($step >> 32) & 0xFFFFFFFF, $step & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $counter, $secret, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
                | (ord($hash[$offset + 1]) << 16)
                | (ord($hash[$offset + 2]) << 8)
                | ord($hash[$offset + 3]);
        return str_pad((string)($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** otpauth://-URL für den QR-Code (rendert das Frontend). */
    public static function otpauthUrl(string $secretB32, string $accountEmail): string {
        return 'otpauth://totp/' . rawurlencode('MBC:' . $accountEmail)
            . '?' . http_build_query([
                'secret' => $secretB32,
                'issuer' => 'MBC',
                'algorithm' => 'SHA1',
                'digits' => self::DIGITS,
                'period' => self::PERIOD,
            ]);
    }

    // ---- Base32 (RFC 4648) ---------------------------------------------------

    /** Ohne Padding — Authenticator-Apps und die otpauth-URL brauchen keins. */
    public static function base32Encode(string $binary): string {
        $out = '';
        $bits = 0;
        $value = 0;
        foreach (str_split($binary) as $byte) {
            $value = ($value << 8) | ord($byte);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out .= self::BASE32_ALPHABET[($value >> $bits) & 0x1F];
            }
        }
        if ($bits > 0) {
            $out .= self::BASE32_ALPHABET[($value << (5 - $bits)) & 0x1F];
        }
        return $out;
    }

    public static function base32Decode(string $b32): string {
        $b32 = strtoupper(rtrim($b32, '='));
        $out = '';
        $bits = 0;
        $value = 0;
        foreach (str_split($b32) as $char) {
            $pos = strpos(self::BASE32_ALPHABET, $char);
            if ($pos === false) {
                throw new \InvalidArgumentException('Invalid base32 character');
            }
            $value = ($value << 5) | $pos;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($value >> $bits) & 0xFF);
            }
        }
        return $out;
    }
}
