<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * GET /gym/settings — Gym-Präferenzen des Users.
 *
 * Liest den generischen settings-JSON-Blob aus mbc_user_settings (geteilt mit
 * Grow, z.B. seed_location_id) und gibt nur die Gym-Keys zurück:
 *
 *  { "health_quick": { "items": [ { "metric": "...", "label": "..." } ] } | null }
 *
 * Legt bei Bedarf einen Default-Datensatz an (wie /grow/settings).
 */
class requestGetGymSettings extends RequestBase {
    public function setRequest(array $request): void { /* keine Params nötig */ }

    public function execute(): void {
        try {
            header('Content-Type: application/json; charset=utf-8');
            $user = $this->requireAuth();
            $userId = (int)$user['users_id'];

            $stmt = $this->pdo->prepare('INSERT IGNORE INTO mbc_user_settings (user_id) VALUES (?)');
            $stmt->execute([$userId]);

            $stmt = $this->pdo->prepare('SELECT settings FROM mbc_user_settings WHERE user_id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $raw = $stmt->fetchColumn();

            $settings = [];
            if (!empty($raw)) {
                $decoded = json_decode((string)$raw, true);
                if (is_array($decoded)) {
                    $settings = $decoded;
                }
            }

            echo json_encode([
                'health_quick' => $settings['gym_health_quick'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $this->handleError('Error fetching gym settings', $e);
        }
    }
}
