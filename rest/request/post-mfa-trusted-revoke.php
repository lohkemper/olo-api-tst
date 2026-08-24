<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /auth/mfa-trusted-revoke { id } | { all: true } — vertrauenswürdige
 * Geräte entziehen (eingeloggt). Löscht nur eigene Zeilen; das zugehörige
 * Cookie wird damit wertlos.
 */
class requestPostMfaTrustedRevoke extends RequestBase {
    private array $data = [];

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');

            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            if (!empty($this->data['all'])) {
                $stmt = $this->pdo->prepare('DELETE FROM ' . PREFIX . '_user_trusted_devices WHERE user_id = ?');
                $stmt->execute([$userId]);
            } else {
                $id = (int)($this->data['id'] ?? 0);
                if ($id <= 0) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Bad Request', 'message' => 'id or all is required']);
                    return;
                }
                $stmt = $this->pdo->prepare('
                    DELETE FROM ' . PREFIX . '_user_trusted_devices
                    WHERE trusted_devices_id = ? AND user_id = ?
                ');
                $stmt->execute([$id, $userId]);
            }

            Logger::info('MFA trusted device(s) revoked', [
                'user_id' => $userId,
                'count' => $stmt->rowCount(),
            ]);
            echo json_encode(['revoked' => $stmt->rowCount()]);
        } catch (\Throwable $e) {
            $this->handleError('Error revoking trusted device', $e);
        }
    }
}
