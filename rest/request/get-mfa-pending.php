<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /auth/mfa-pending — liefert den ausstehenden MFA-Challenge-Zustand.
 *
 * Von /login/verify beim Laden abgefragt (deckt Passwort- UND Social-Herkunft
 * ab) und liefert das CSRF-Token für den folgenden mfa-verify-POST mit.
 * 404, wenn keine Challenge aussteht oder die TTL abgelaufen ist.
 */
class requestGetMfaPending extends RequestBase {
    public function setRequest(array $request): void { /* keine Params */ }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $pending = MfaHelper::pending();
            if ($pending === null) {
                http_response_code(404);
                echo json_encode(['error' => 'Not Found', 'message' => 'No pending MFA challenge']);
                return;
            }

            echo json_encode([
                'methods' => $pending['methods'],
                'backupCodesAvailable' => (bool)$pending['backup'],
                'origin' => $pending['origin'],
                // Bestehendes Token wiederverwenden (kein Invalidieren anderer Tabs).
                'csrfToken' => CsrfHelper::getToken() ?? CsrfHelper::generateToken(),
            ]);
        } catch (\Throwable $e) {
            $this->handleError('Error reading pending MFA challenge', $e);
        }
    }
}
