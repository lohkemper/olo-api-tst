<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /grow/settings — Speichert die Google-Kalender-Konfiguration des Users.
 *
 * Body (optional): { "gcal_enabled": true, "gcal_calendar_id": "primary" }
 *
 * Token-Felder (gcal_access_token/refresh_token) werden NICHT hierüber gesetzt —
 * das übernimmt später der OAuth-Callback. Antwort enthält keine Tokens.
 */
class requestPostGrowSettings extends RequestBase {
    private array $data = [];

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');
            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $stmt = $this->pdo->prepare('INSERT IGNORE INTO mbc_user_settings (user_id) VALUES (?)');
            $stmt->execute([$userId]);

            $sets = [];
            $params = [];

            if (array_key_exists('gcal_enabled', $this->data)) {
                $sets[] = 'gcal_enabled = ?';
                $params[] = !empty($this->data['gcal_enabled']) ? 1 : 0;
            }
            if (array_key_exists('gcal_calendar_id', $this->data)) {
                $val = trim((string)$this->data['gcal_calendar_id']);
                $sets[] = 'gcal_calendar_id = ?';
                $params[] = $val === '' ? null : $val;
            }
            // seed_location_id wird in den settings-JSON-Blob gemergt (kein eigenes Spalten-Schema nötig)
            if (array_key_exists('seed_location_id', $this->data)) {
                $cur = $this->pdo->prepare('SELECT settings FROM mbc_user_settings WHERE user_id = ?');
                $cur->execute([$userId]);
                $existing = $cur->fetchColumn();
                $settings = [];
                if (!empty($existing)) {
                    $decoded = json_decode((string)$existing, true);
                    if (is_array($decoded)) {
                        $settings = $decoded;
                    }
                }
                $loc = $this->data['seed_location_id'];
                if ($loc === null || $loc === '' || !is_numeric($loc) || (int)$loc <= 0) {
                    unset($settings['seed_location_id']);
                } else {
                    $settings['seed_location_id'] = (int)$loc;
                }
                $sets[] = 'settings = ?';
                $params[] = empty($settings) ? null : json_encode($settings);
            } elseif (array_key_exists('settings', $this->data)) {
                $sets[] = 'settings = ?';
                $params[] = $this->data['settings'] === null ? null : json_encode($this->data['settings']);
            }

            if (!empty($sets)) {
                $params[] = $userId;
                $stmt = $this->pdo->prepare(
                    'UPDATE mbc_user_settings SET ' . implode(', ', $sets) . ' WHERE user_id = ?'
                );
                $stmt->execute($params);
            }

            $stmt = $this->pdo->prepare(
                'SELECT user_settings_id, user_id, gcal_enabled, gcal_calendar_id,
                        (gcal_refresh_token IS NOT NULL) AS gcal_connected,
                        gcal_token_expires_at, settings, created_at, updated_at
                 FROM mbc_user_settings WHERE user_id = ? LIMIT 1'
            );
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

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
            $this->handleError('Error saving user settings', $e);
        }
    }
}
