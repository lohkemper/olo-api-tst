<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Simple Rate Limiter
 * Prevents brute-force attacks by limiting requests per IP
 *
 * For production, consider using Redis/Memcached for distributed rate limiting
 */
class RateLimiter {
    private PDO $pdo;
    private string $table = 'mbc_rate_limit';

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->ensureTableExists();
    }

    /**
     * Create rate limit table if it doesn't exist
     */
    private function ensureTableExists(): void {
        $sql = "
            CREATE TABLE IF NOT EXISTS {$this->table} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ip_address VARCHAR(45) NOT NULL,
                endpoint VARCHAR(255) NOT NULL,
                attempts INT NOT NULL DEFAULT 1,
                first_attempt_at DATETIME NOT NULL,
                last_attempt_at DATETIME NOT NULL,
                INDEX idx_ip_endpoint (ip_address, endpoint),
                INDEX idx_last_attempt (last_attempt_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        $this->pdo->exec($sql);
    }

    /**
     * Check if request should be rate limited
     *
     * @param string $endpoint Endpoint identifier (e.g., 'login', 'register')
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $windowSeconds Time window in seconds
     * @return array ['allowed' => bool, 'remainingAttempts' => int, 'resetAt' => ?string]
     */
    public function check(string $endpoint, int $maxAttempts = 5, int $windowSeconds = 300): array {
        $ipAddress = $this->getClientIp();

        // Clean up old records
        $this->cleanup($windowSeconds);

        // Get current rate limit record
        $stmt = $this->pdo->prepare("
            SELECT attempts, first_attempt_at, last_attempt_at
            FROM {$this->table}
            WHERE ip_address = :ip
            AND endpoint = :endpoint
            AND last_attempt_at > DATE_SUB(NOW(), INTERVAL :window SECOND)
            LIMIT 1
        ");

        $stmt->execute([
            'ip' => $ipAddress,
            'endpoint' => $endpoint,
            'window' => $windowSeconds
        ]);

        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            // No recent attempts - allow request
            return [
                'allowed' => true,
                'remainingAttempts' => $maxAttempts - 1,
                'resetAt' => null
            ];
        }

        $attempts = (int)$record['attempts'];

        if ($attempts >= $maxAttempts) {
            // Rate limit exceeded
            $resetAt = date('Y-m-d H:i:s', strtotime($record['first_attempt_at']) + $windowSeconds);

            return [
                'allowed' => false,
                'remainingAttempts' => 0,
                'resetAt' => $resetAt,
                'retryAfter' => max(0, strtotime($resetAt) - time())
            ];
        }

        // Still within limit
        return [
            'allowed' => true,
            'remainingAttempts' => $maxAttempts - $attempts - 1,
            'resetAt' => date('Y-m-d H:i:s', strtotime($record['first_attempt_at']) + $windowSeconds)
        ];
    }

    /**
     * Record an attempt
     */
    public function hit(string $endpoint): void {
        $ipAddress = $this->getClientIp();

        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->table} (ip_address, endpoint, attempts, first_attempt_at, last_attempt_at)
            VALUES (:ip, :endpoint, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                attempts = attempts + 1,
                last_attempt_at = NOW()
        ");

        $stmt->execute([
            'ip' => $ipAddress,
            'endpoint' => $endpoint
        ]);
    }

    /**
     * Reset rate limit for IP and endpoint (e.g., after successful login)
     */
    public function reset(string $endpoint, ?string $ip = null): void {
        $ip = $ip ?? $this->getClientIp();

        $stmt = $this->pdo->prepare("
            DELETE FROM {$this->table}
            WHERE ip_address = :ip AND endpoint = :endpoint
        ");

        $stmt->execute([
            'ip' => $ip,
            'endpoint' => $endpoint
        ]);
    }

    /**
     * Clean up old rate limit records
     */
    private function cleanup(int $windowSeconds): void {
        // Only run cleanup occasionally (1% chance) to avoid overhead
        if (rand(1, 100) > 1) {
            return;
        }

        $stmt = $this->pdo->prepare("
            DELETE FROM {$this->table}
            WHERE last_attempt_at < DATE_SUB(NOW(), INTERVAL :window SECOND)
        ");

        $stmt->execute(['window' => $windowSeconds]);
    }

    /**
     * Get client IP address (handles proxies)
     */
    private function getClientIp(): string {
        // Check for IP behind proxy
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
            // X-Forwarded-For can contain multiple IPs, get the first one
            $ips = explode(',', $ip);
            $ip = trim($ips[0]);
        } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        }

        // Validate IP
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $ip = '0.0.0.0';
        }

        return $ip;
    }

    /**
     * Require rate limit check - sends 429 if limit exceeded
     */
    public function requireLimit(string $endpoint, int $maxAttempts = 5, int $windowSeconds = 300): void {
        $result = $this->check($endpoint, $maxAttempts, $windowSeconds);

        if (!$result['allowed']) {
            http_response_code(429); // Too Many Requests
            header('Retry-After: ' . ($result['retryAfter'] ?? 60));
            echo json_encode([
                'error' => 'Too Many Requests',
                'message' => 'Rate limit exceeded. Please try again later.',
                'retryAfter' => $result['retryAfter'] ?? 60,
                'resetAt' => $result['resetAt'] ?? null
            ]);
            exit;
        }

        // Record this attempt
        $this->hit($endpoint);
    }
}
