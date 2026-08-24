<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * DELETE /auth/webauthn-credentials?id={n} — Passkey löschen (eingeloggt).
 * Wird der letzte Passkey entfernt, verschwindet die Registry-Methode
 * 'webauthn' (inkl. Cleanup, falls es die letzte MFA-Methode war).
 */
class requestDeleteWebauthnCredentials extends RequestBase {
    private array $request = [];

    public function setRequest(array $request): void {
        $this->request = $request;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $id = (int)($this->request['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Bad Request', 'message' => 'id is required']);
                return;
            }

            $stmt = $this->pdo->prepare('
                DELETE FROM ' . PREFIX . '_user_webauthn_credentials
                WHERE user_webauthn_credentials_id = ? AND user_id = ?
            ');
            $stmt->execute([$id, $userId]);
            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Not Found']);
                return;
            }

            $remaining = $this->pdo->prepare('
                SELECT COUNT(*) FROM ' . PREFIX . '_user_webauthn_credentials WHERE user_id = ?
            ');
            $remaining->execute([$userId]);
            $methodStillEnabled = (int)$remaining->fetchColumn() > 0;
            if (!$methodStillEnabled) {
                MfaHelper::removeMethod($this->pdo, $userId, 'webauthn');
            }

            Logger::logSecurityEvent('WebAuthn credential deleted', ['user_id' => $userId, 'credential' => $id]);
            echo json_encode(['deleted' => true, 'methodStillEnabled' => $methodStillEnabled]);
        } catch (\Throwable $e) {
            $this->handleError('Error deleting WebAuthn credential', $e);
        }
    }
}
