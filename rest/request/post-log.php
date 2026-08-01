<?php
declare(strict_types=1);
// SPDX-License-Identifier: LicenseRef-OliverLohkemper-Proprietary

if (!STOKEN) die('SEC');

/**
 * POST /log
 *
 * Persistiert Frontend-Logs des WebApi-Publishers aus @olo/core/logs in
 * die Tabelle `mbc_logs`. Bewusst ohne Auth-Pflicht — Logs entstehen auch
 * vor dem Login (z. B. Boot-/Fehler-Logs). Ist ein User eingeloggt, wird
 * seine ID mitgeschrieben (Audit-Trail).
 *
 * Body-Format (siehe LogEntry.buildLogString()):
 * {
 *   "string": "INFO ['2026-05-30T...'] \"msg\" - Params: [...]",
 *   "level": "INFO"   // optional, sonst aus dem String geparst
 * }
 *
 * Antwort: 201 { "status": "ok", "id": <logs_id> }
 */
class requestPostLog extends RequestBase {
    private array $data = [];

    private const MAX_MESSAGE_LENGTH = 8000;
    private const VALID_LEVELS = ['DEBUG', 'INFO', 'WARN', 'ERROR', 'FATAL', 'LOG'];

    public function __construct(PDO $pdo, string $area) {
        parent::__construct($pdo, $area);
    }

    public function setData(array $data): void {
        $this->data = $data;
    }

    public function execute(): void {
        try {
            // Generöses Rate-Limit pro IP — schützt vor Log-Flooding/Abuse,
            // ohne normales SPA-Log-Volumen zu drosseln.
            (new RateLimiter($this->pdo))->requireLimit('log', 300, 60);

            $message = isset($this->data['string']) && is_string($this->data['string'])
                ? trim($this->data['string'])
                : '';

            if ($message === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Missing or empty "string" field']);
                return;
            }

            if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
                $message = mb_substr($message, 0, self::MAX_MESSAGE_LENGTH);
            }

            $user   = $this->getCurrentUser();
            $userId = isset($user['users_id']) ? (int)$user['users_id'] : null;

            $stmt = $this->pdo->prepare(
                'INSERT INTO mbc_logs (level, message, user_id, source, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $this->resolveLevel($message),
                $message,
                $userId,
                'web',
                $this->clientIp(),
                mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);

            http_response_code(201);
            echo json_encode([
                'status' => 'ok',
                'id'     => (int)$this->pdo->lastInsertId(),
            ]);

        } catch (\Throwable $e) {
            $this->handleError('Error persisting log entry', $e);
        }
    }

    /**
     * Best-effort Level-Extraktion: explizites `level`-Feld bevorzugt,
     * sonst das führende Token des buildLogString()-Formats
     * ("LEVEL [date] \"msg\" ..."). Fallback: INFO.
     */
    private function resolveLevel(string $message): string {
        if (isset($this->data['level']) && is_string($this->data['level'])) {
            $explicit = strtoupper(trim($this->data['level']));
            if (in_array($explicit, self::VALID_LEVELS, true)) {
                return $explicit;
            }
        }
        if (preg_match('/^([A-Z]+)\b/', $message, $m)) {
            $candidate = strtoupper($m[1]);
            if (in_array($candidate, self::VALID_LEVELS, true)) {
                return $candidate;
            }
        }
        return 'INFO';
    }

    /**
     * Client-IP (proxy-aware, validiert). Analog RateLimiter::getClientIp.
     */
    private function clientIp(): string {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip  = trim($ips[0]);
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        }
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }
}
