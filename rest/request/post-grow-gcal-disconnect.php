<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /grow/gcal-disconnect — trennt die Google-Kalender-Verbindung (Tokens löschen).
 */
class requestPostGrowGcalDisconnect extends RequestBase {
    public function setData(array $data): void { /* keine Daten nötig */ }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');
            $user = $this->requireAuth();
            GoogleOAuth::disconnect($this->pdo, (int)$user['users_id']);
            echo json_encode(['ok' => true, 'gcal_connected' => 0]);
        } catch (\Throwable $e) {
            $this->handleError('Error disconnecting Google Calendar', $e);
        }
    }
}
