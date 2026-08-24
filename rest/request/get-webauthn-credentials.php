<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /auth/webauthn-credentials — Passkey-Liste des eingeloggten Users
 * (Settings-Sicherheits-Tab).
 */
class requestGetWebauthnCredentials extends RequestBase {
    public function setRequest(array $request): void { /* keine Params */ }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();

            $stmt = $this->pdo->prepare('
                SELECT user_webauthn_credentials_id, label, transports, created_at, last_used_at
                FROM ' . PREFIX . '_user_webauthn_credentials
                WHERE user_id = :userId
                ORDER BY created_at ASC
            ');
            $stmt->execute(['userId' => (int)$user['users_id']]);

            echo json_encode([
                'credentials' => array_map(static fn(array $row) => [
                    'id' => (int)$row['user_webauthn_credentials_id'],
                    'label' => $row['label'],
                    'transports' => $row['transports'] ? explode(',', (string)$row['transports']) : [],
                    'createdAt' => $row['created_at'],
                    'lastUsedAt' => $row['last_used_at'],
                ], $stmt->fetchAll(PDO::FETCH_ASSOC)),
                'rpId' => WebAuthnHelper::rpId(),
            ]);
        } catch (\Throwable $e) {
            $this->handleError('Error loading WebAuthn credentials', $e);
        }
    }
}
