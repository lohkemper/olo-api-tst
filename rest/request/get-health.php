<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * Health Check Endpoint
 * Handles: GET /api/health
 *
 * Provides system health status for monitoring and alerting
 */
class requestGetHealth extends RequestBase {
    public function execute(): void {
        try {
            $health = [
                'status' => 'healthy',
                'timestamp' => date('Y-m-d H:i:s'),
                'checks' => []
            ];

            // Check database connection
            $dbCheck = $this->checkDatabase();
            $health['checks']['database'] = $dbCheck;

            // Check session functionality
            $sessionCheck = $this->checkSession();
            $health['checks']['session'] = $sessionCheck;

            // Check file system (logs directory writable)
            $fsCheck = $this->checkFileSystem();
            $health['checks']['filesystem'] = $fsCheck;

            // Check environment configuration
            $envCheck = $this->checkEnvironment();
            $health['checks']['environment'] = $envCheck;

            // Determine overall status
            $allHealthy = true;
            foreach ($health['checks'] as $check) {
                if ($check['status'] !== 'ok') {
                    $allHealthy = false;
                    break;
                }
            }

            if (!$allHealthy) {
                $health['status'] = 'unhealthy';
                http_response_code(503); // Service Unavailable
            } else {
                http_response_code(200);
            }

            // Add system info
            $health['system'] = [
                'php_version' => PHP_VERSION,
                'memory_usage' => $this->formatBytes(memory_get_usage(true)),
                'memory_peak' => $this->formatBytes(memory_get_peak_usage(true)),
                'uptime' => $this->getUptime()
            ];

            header('Content-Type: application/json');
            echo json_encode($health, JSON_PRETTY_PRINT);

        } catch (\Throwable $e) {
            http_response_code(503);
            echo json_encode([
                'status' => 'error',
                'message' => DEBUG ? $e->getMessage() : 'Health check failed',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }
    }

    /**
     * Check database connectivity
     */
    private function checkDatabase(): array {
        try {
            $stmt = $this->pdo->query('SELECT 1');
            $result = $stmt->fetch();

            return [
                'status' => 'ok',
                'message' => 'Database connection successful',
                'response_time_ms' => 0 // Could measure actual query time
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => DEBUG ? $e->getMessage() : 'Database connection failed'
            ];
        }
    }

    /**
     * Check session functionality
     */
    private function checkSession(): array {
        try {
            if (session_status() === PHP_SESSION_ACTIVE) {
                return [
                    'status' => 'ok',
                    'message' => 'Session active'
                ];
            } else {
                return [
                    'status' => 'warning',
                    'message' => 'Session not started'
                ];
            }
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => 'Session check failed'
            ];
        }
    }

    /**
     * Check file system (logs directory writable)
     */
    private function checkFileSystem(): array {
        try {
            $logsDir = __DIR__ . '/../logs';

            if (!file_exists($logsDir)) {
                return [
                    'status' => 'warning',
                    'message' => 'Logs directory does not exist'
                ];
            }

            if (!is_writable($logsDir)) {
                return [
                    'status' => 'error',
                    'message' => 'Logs directory is not writable'
                ];
            }

            return [
                'status' => 'ok',
                'message' => 'Filesystem writable',
                'logs_directory' => $logsDir
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => 'Filesystem check failed'
            ];
        }
    }

    /**
     * Check environment configuration
     */
    private function checkEnvironment(): array {
        $requiredVars = ['DB_DSN', 'DB_USERNAME', 'DB_PASSWORD', 'DB_PREFIX', 'JWT_SECRET', 'DEBUG'];
        $missing = [];

        foreach ($requiredVars as $var) {
            if (!isset($_ENV[$var])) {
                $missing[] = $var;
            }
        }

        if (count($missing) > 0) {
            return [
                'status' => 'error',
                'message' => 'Missing environment variables',
                'missing' => $missing
            ];
        }

        return [
            'status' => 'ok',
            'message' => 'All environment variables present'
        ];
    }

    /**
     * Get server uptime
     */
    private function getUptime(): string {
        if (function_exists('sys_getloadavg')) {
            $uptime = shell_exec('uptime');
            return trim($uptime ?: 'unknown');
        }

        return 'unknown';
    }

    /**
     * Format bytes to human-readable format
     */
    private function formatBytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
