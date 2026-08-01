<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /grow/settings — Pro-User-Einstellungen (Google-Kalender-Config).
 *
 * Gibt niemals Tokens zurück — nur ob eine Verbindung besteht (`gcal_connected`).
 * Legt bei Bedarf einen Default-Datensatz an.
 */
class requestGetGrowSettings extends RequestBase {
    public function setRequest(array $request): void { /* keine Params nötig */ }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');
            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            // Default-Datensatz sicherstellen
            $stmt = $this->pdo->prepare('INSERT IGNORE INTO mbc_user_settings (user_id) VALUES (?)');
            $stmt->execute([$userId]);

            $stmt = $this->pdo->prepare(
                'SELECT user_settings_id, user_id, gcal_enabled, gcal_calendar_id,
                        (gcal_refresh_token IS NOT NULL) AS gcal_connected,
                        gcal_token_expires_at, settings, created_at, updated_at
                 FROM mbc_user_settings WHERE user_id = ? LIMIT 1'
            );
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $row = ['user_id' => $userId, 'gcal_enabled' => 0, 'gcal_calendar_id' => null, 'gcal_connected' => 0];
            }

            // seed_location_id aus dem settings-JSON-Blob als First-Class-Feld ausgeben
            $settings = [];
            if (!empty($row['settings'])) {
                $decoded = json_decode((string)$row['settings'], true);
                if (is_array($decoded)) {
                    $settings = $decoded;
                }
            }
            $row['seed_location_id'] = isset($settings['seed_location_id']) && $settings['seed_location_id'] !== null
                ? (int)$settings['seed_location_id']
                : null;

            echo json_encode($row);
        } catch (\Throwable $e) {
            $this->handleError('Error fetching user settings', $e);
        }
    }
}
