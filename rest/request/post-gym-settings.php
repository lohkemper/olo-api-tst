<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * POST /gym/settings — Speichert Gym-Präferenzen des Users (Teil-Update).
 *
 * Body: { "health_quick": { "items": [ { "metric": "hemoglobin", "label": "Hb" } ] } | null }
 *
 * `health_quick` wird als Key `gym_health_quick` in den settings-JSON-Blob von
 * mbc_user_settings gemergt — andere Blob-Keys (z.B. Grow) bleiben unberührt.
 * null entfernt den Key (Client fällt auf seine Default-Liste zurück).
 *
 * Validierung wie beim blood-values-Endpoint bewusst schlank: metric nur als
 * Key-Format geprüft (Katalog lebt im Frontend), Labels als freier Text mit
 * Längen-Limit, Item-Anzahl gedeckelt.
 */
class requestPostGymSettings extends RequestBase {
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

            if (array_key_exists('health_quick', $this->data)) {
                $healthQuick = null;
                if ($this->data['health_quick'] !== null) {
                    $healthQuick = $this->sanitizeHealthQuick($this->data['health_quick']);
                    if ($healthQuick === null) {
                        http_response_code(400);
                        echo json_encode(['error' => 'health_quick must be null or { items: [{ metric, label? }] }']);
                        return;
                    }
                }

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

                if ($healthQuick === null || empty($healthQuick['items'])) {
                    // null oder leere Liste → zurück auf die Client-Default-Liste
                    unset($settings['gym_health_quick']);
                } else {
                    $settings['gym_health_quick'] = $healthQuick;
                }

                $stmt = $this->pdo->prepare('UPDATE mbc_user_settings SET settings = ? WHERE user_id = ?');
                $stmt->execute([
                    empty($settings) ? null : json_encode($settings, JSON_UNESCAPED_UNICODE),
                    $userId,
                ]);
            }

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
            $this->handleError('Error saving gym settings', $e);
        }
    }

    /**
     * { items: [{ metric, label? }] } säubern; null bei strukturell ungültiger
     * Eingabe (→ 400), einzelne kaputte Items werden still verworfen.
     */
    private function sanitizeHealthQuick(mixed $raw): ?array {
        if (!is_array($raw) || !isset($raw['items']) || !is_array($raw['items'])) {
            return null;
        }

        $items = [];
        $seen = [];
        foreach ($raw['items'] as $item) {
            if (count($items) >= 100) break;
            if (!is_array($item)) continue;

            $metric = $item['metric'] ?? null;
            if (!is_string($metric) || !preg_match('/^[a-z0-9_]{1,40}$/', $metric)) continue;
            if (isset($seen[$metric])) continue;
            $seen[$metric] = true;

            $clean = ['metric' => $metric];
            if (isset($item['label']) && is_string($item['label'])) {
                $label = trim($item['label']);
                if ($label !== '') {
                    $clean['label'] = mb_substr($label, 0, 80);
                }
            }
            $items[] = $clean;
        }

        return ['items' => $items];
    }
}
