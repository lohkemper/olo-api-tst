<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

// lbuchs/WebAuthn v2.2.0 manuell vendored (lib/webauthn/) — WebAuthn.php
// lädt seine Teildateien selbst per require_once.
require_once __DIR__ . '/webauthn/WebAuthn.php';

use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\Binary\ByteBuffer;

/**
 * WebAuthn/Passkeys als zweiter Faktor (Plan: docs/planning/mfa-2fa.md).
 *
 * .env: WEBAUTHN_RP_ID (registrierbare Domain, z.B. oliverlohkemper.de),
 *       WEBAUTHN_RP_NAME, WEBAUTHN_ALLOWED_ORIGINS (Komma-Liste).
 * Challenge liegt in $_SESSION['webauthn_challenge'] (TTL 300 s, One-Time).
 * Attestation 'none', userVerification 'preferred', kein Resident-Key-Zwang.
 */
final class WebAuthnHelper {
    public const CHALLENGE_TTL = 300;

    public static function isConfigured(): bool {
        return self::rpId() !== '';
    }

    public static function rpId(): string {
        return (string)($_ENV['WEBAUTHN_RP_ID'] ?? '');
    }

    public static function make(): WebAuthn {
        // base64url-Encoding: getCreateArgs/getGetArgs liefern direkt
        // FE-taugliche Strings (webauthn.util.ts decodiert zu ArrayBuffer).
        return new WebAuthn(
            (string)($_ENV['WEBAUTHN_RP_NAME'] ?? 'MBC'),
            self::rpId(),
            ['none'],
            true
        );
    }

    /** Origin-Header gegen die .env-Whitelist prüfen (zusätzlich zur lbuchs-Prüfung). */
    public static function originAllowed(): bool {
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin === '') {
            return true; // kein Origin-Header (z.B. Same-Origin-GET) — lbuchs prüft clientData.origin
        }
        $allowed = array_filter(array_map('trim',
            explode(',', (string)($_ENV['WEBAUTHN_ALLOWED_ORIGINS'] ?? ''))));
        return in_array($origin, $allowed, true);
    }

    // ---- Challenge (Session, One-Time) ---------------------------------------

    public static function storeChallenge(ByteBuffer $challenge, string $type): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['webauthn_challenge'] = [
            'value' => bin2hex($challenge->getBinaryString()),
            'type' => $type, // 'create' | 'get'
            'ts' => time(),
        ];
    }

    /** Liefert die Challenge binär und löscht sie (One-Time; null bei TTL/Typ-Mismatch). */
    public static function consumeChallenge(string $type): ?string {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $stored = $_SESSION['webauthn_challenge'] ?? null;
        unset($_SESSION['webauthn_challenge']);
        if (!is_array($stored)
            || ($stored['type'] ?? '') !== $type
            || (time() - (int)($stored['ts'] ?? 0)) >= self::CHALLENGE_TTL) {
            return null;
        }
        return hex2bin((string)$stored['value']) ?: null;
    }

    // ---- Assertion-Prüfung (vom Kern-mfa-verify aufgerufen) ------------------

    /**
     * Prüft eine Login-Assertion: Credential wird per credential_id UND userId
     * geladen (fremde Credentials matchen nie), Signatur via lbuchs::processGet,
     * signCount warn-only (Passkeys melden oft konstant 0).
     */
    public static function verifyAssertion(PDO $pdo, int $userId, array $assertion): bool {
        try {
            if (!self::isConfigured() || !self::originAllowed()) {
                return false;
            }
            $challenge = self::consumeChallenge('get');
            if ($challenge === null) {
                return false;
            }

            $credentialId = (string)($assertion['rawId'] ?? $assertion['id'] ?? '');
            $response = $assertion['response'] ?? [];
            $clientDataJSON = self::b64urlDecode((string)($response['clientDataJSON'] ?? ''));
            $authenticatorData = self::b64urlDecode((string)($response['authenticatorData'] ?? ''));
            $signature = self::b64urlDecode((string)($response['signature'] ?? ''));
            if ($credentialId === '' || $clientDataJSON === '' || $authenticatorData === '' || $signature === '') {
                return false;
            }

            $stmt = $pdo->prepare('
                SELECT user_webauthn_credentials_id, public_key, sign_count
                FROM ' . PREFIX . '_user_webauthn_credentials
                WHERE credential_id = :credId AND user_id = :userId
                LIMIT 1
            ');
            $stmt->execute(['credId' => self::normalizeB64url($credentialId), 'userId' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return false;
            }

            // prevSignatureCnt = null: lbuchs' Hard-Fail umgehen, Anomalie nur loggen.
            self::make()->processGet(
                $clientDataJSON,
                $authenticatorData,
                $signature,
                (string)$row['public_key'],
                $challenge,
                null,
                false, // userVerification: preferred
                true
            );

            $authObj = new \lbuchs\WebAuthn\Attestation\AuthenticatorData($authenticatorData);
            $reported = (int)$authObj->getSignCount();
            $stored = (int)$row['sign_count'];
            if ($reported > 0 && $reported <= $stored) {
                Logger::warning('WebAuthn signCount anomaly (possible clone)', [
                    'user_id' => $userId, 'stored' => $stored, 'reported' => $reported,
                ]);
            }

            $pdo->prepare('
                UPDATE ' . PREFIX . '_user_webauthn_credentials
                SET sign_count = :count, last_used_at = NOW()
                WHERE user_webauthn_credentials_id = :id
            ')->execute([
                'count' => max($reported, $stored),
                'id' => (int)$row['user_webauthn_credentials_id'],
            ]);
            return true;
        } catch (\Throwable $e) {
            Logger::warning('WebAuthn assertion rejected', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    // ---- base64url -----------------------------------------------------------

    public static function b64urlDecode(string $s): string {
        $decoded = base64_decode(strtr($s, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }

    public static function b64urlEncode(string $binary): string {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    /** id kann bereits base64url sein — auf kanonische Form (ohne Padding) bringen. */
    public static function normalizeB64url(string $s): string {
        $bin = self::b64urlDecode($s);
        return $bin === '' ? $s : self::b64urlEncode($bin);
    }
}
