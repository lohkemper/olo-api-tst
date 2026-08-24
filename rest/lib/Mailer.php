<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

// PHPMailer manuell vendored (lib/phpmailer/, v6.9.3) — bewusst ohne Composer,
// damit der WinSCP-Deploy ohne vendor/-Autoload-Regeneration auskommt.
require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Transaktionaler Mail-Versand (SMTP) — generischer Grundstein.
 * Erste Nutzer: MFA-E-Mail-Codes; später Passwort-Reset/Willkommensmail.
 *
 * Konfiguration über .env (Muster GoogleOAuth::isConfigured):
 *   SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASSWORD,
 *   SMTP_ENCRYPTION (tls -> STARTTLS, ssl -> SMTPS, none),
 *   SMTP_FROM_EMAIL, SMTP_FROM_NAME (Default "MBC")
 *
 * send() wirft nie nach außen: Fehler werden geloggt (ohne Mail-Inhalt!)
 * und als false gemeldet — Aufrufer entscheiden über die Nutzerantwort.
 */
final class Mailer {

    public static function isConfigured(): bool {
        return self::env('SMTP_HOST') !== ''
            && self::env('SMTP_USER') !== ''
            && self::env('SMTP_PASSWORD') !== ''
            && self::env('SMTP_FROM_EMAIL') !== '';
    }

    public static function send(string $to, string $subject, string $htmlBody, string $textBody = ''): bool {
        if (!self::isConfigured()) {
            Logger::error('Mail send skipped - SMTP not configured', ['to' => $to]);
            return false;
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = self::env('SMTP_HOST');
            $mail->Port = (int)(self::env('SMTP_PORT') ?: 587);
            $mail->SMTPAuth = true;
            $mail->Username = self::env('SMTP_USER');
            $mail->Password = self::env('SMTP_PASSWORD');

            $encryption = strtolower(self::env('SMTP_ENCRYPTION') ?: 'tls');
            if ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;      // Port 465
            } elseif ($encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;   // Port 587
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

            $mail->CharSet = 'UTF-8';
            $mail->Timeout = 15;

            $mail->setFrom(self::env('SMTP_FROM_EMAIL'), self::env('SMTP_FROM_NAME') ?: 'MBC');
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody !== '' ? $textBody : strip_tags($htmlBody);

            $mail->send();
            return true;
        } catch (\Throwable $e) {
            // Kein Body/Code im Log — nur Metadaten.
            Logger::error('Mail send failed', [
                'to' => $to,
                'subject' => $subject,
                'error' => $mail->ErrorInfo ?: $e->getMessage(),
            ]);
            return false;
        }
    }

    private static function env(string $key): string {
        return (string)($_ENV[$key] ?? '');
    }
}
