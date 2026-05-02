<?php
// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Setzt Security-Header direkt in PHP, weil die Ionos-Infrastruktur
 * .htaccess Header-Direktiven auf PHP-Responses nicht zuverlaessig
 * anwendet (nur auf von Apache direkt beantworteten Responses wie
 * Redirects). Aufruf aus index.php so frueh wie moeglich.
 */
class SecurityHeaders {
    public static function apply(): void {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');

        if (self::isHttps()) {
            // RFC 6797: HSTS MUST NOT be sent over plain HTTP
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    private static function isHttps(): bool {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            return true;
        }
        if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        return false;
    }
}
