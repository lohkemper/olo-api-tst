<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /auth/mfa-email-send — sendet einen 6-stelligen Einmalcode.
 *
 * Dual-State:
 *   (1) gültige MFA-Challenge (mfa_pending)   → purpose 'login'
 *   (2) sonst eingeloggt (JWT)                → purpose 'enroll' (Aktivierung)
 *
 * Antwortet unabhängig vom SMTP-Ausgang identisch (kein Delivery-Oracle);
 * Versandfehler landen nur im Log.
 */
class requestPostMfaEmailSend extends RequestBase {
    private array $data = [];

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $rateLimiter = new RateLimiter($this->pdo);
            $rateLimiter->requireLimit('mfa-email-send', 3, 300);

            if (!Mailer::isConfigured()) {
                http_response_code(503);
                echo json_encode(['error' => 'Email delivery is not configured on the server']);
                return;
            }

            // Zustand bestimmen: Challenge (pre-auth) vor JWT (Enrollment).
            $pending = MfaHelper::pending();
            if ($pending !== null) {
                $userId = (int)$pending['userId'];
                $purpose = 'login';
            } else {
                $user = $this->requireAuth();
                $userId = (int)$user['users_id'];
                $purpose = 'enroll';
            }

            $userRow = MfaHelper::loadUserRow($this->pdo, $userId);
            if (!$userRow) {
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized']);
                return;
            }

            $retryAfter = null;
            $code = MfaEmailCode::issue($this->pdo, $userId, $purpose, $retryAfter);
            if ($code === null) {
                http_response_code(429);
                echo json_encode(['error' => 'Too many requests', 'retryAfter' => $retryAfter]);
                return;
            }

            $subject = 'Dein MBC-Bestätigungscode: ' . $code;
            $html = '<p>Dein Bestätigungscode lautet:</p>'
                . '<p><strong style="font-size:24px;letter-spacing:4px">' . $code . '</strong></p>'
                . '<p>Der Code ist 10 Minuten gültig. Wenn du diese Anmeldung nicht angefordert hast, '
                . 'ändere dein Passwort. Gib den Code niemals weiter.</p>';
            $text = "Dein Bestätigungscode lautet: {$code}\n\n"
                . "Der Code ist 10 Minuten gültig. Wenn du diese Anmeldung nicht angefordert hast, "
                . "ändere dein Passwort. Gib den Code niemals weiter.";
            Mailer::send((string)$userRow['email'], $subject, $html, $text);

            Logger::info('MFA email code sent', ['user_id' => $userId, 'purpose' => $purpose]);
            echo json_encode([
                'sent' => true,
                'cooldownSeconds' => MfaEmailCode::RESEND_COOLDOWN,
                'expiresInSeconds' => MfaEmailCode::TTL,
            ]);
        } catch (\Throwable $e) {
            $this->handleError('Error sending MFA email code', $e);
        }
    }
}
