<?php
declare(strict_types=1);

use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Processor\WebProcessor;
use Monolog\Processor\IntrospectionProcessor;
use Monolog\LogRecord;

/**
 * Centralized Application Logger
 * Uses Monolog for structured logging with rotation and context
 */
class Logger {
    private static ?MonologLogger $logger = null;
    private static string $logPath = __DIR__ . '/../logs';

    /**
     * Get singleton logger instance
     */
    public static function getInstance(): MonologLogger {
        if (self::$logger === null) {
            self::$logger = self::createLogger();
        }

        return self::$logger;
    }

    /**
     * Create and configure Monolog logger
     */
    private static function createLogger(): MonologLogger {
        // Ensure log directory exists
        if (!file_exists(self::$logPath)) {
            mkdir(self::$logPath, 0755, true);
        }

        $logger = new MonologLogger('mbc-api');

        // Determine log level from environment
        $logLevel = match(strtolower($_ENV['LOG_LEVEL'] ?? 'info')) {
            'debug' => MonologLogger::DEBUG,
            'info' => MonologLogger::INFO,
            'warning' => MonologLogger::WARNING,
            'error' => MonologLogger::ERROR,
            'critical' => MonologLogger::CRITICAL,
            default => MonologLogger::INFO
        };

        // Add handlers
        if (DEBUG && php_sapi_name() === 'cli') {
            // Development: Log to stdout only in CLI mode (not web requests)
            // In web mode, stdout would send headers and break session handling
            $logger->pushHandler(self::createConsoleHandler($logLevel));
        }

        // Always log to rotating files
        $logger->pushHandler(self::createFileHandler($logLevel));
        $logger->pushHandler(self::createErrorFileHandler());

        // Add processors for additional context
        $logger->pushProcessor(new WebProcessor());           // Add $_SERVER data
        $logger->pushProcessor(new IntrospectionProcessor()); // Add file/line/class info
        $logger->pushProcessor([self::class, 'addCustomContext']); // Custom context

        return $logger;
    }

    /**
     * Create console handler (stdout) for development
     */
    private static function createConsoleHandler(int $level): StreamHandler {
        $handler = new StreamHandler('php://stdout', $level);
        $handler->setFormatter(self::createFormatter());
        return $handler;
    }

    /**
     * Create rotating file handler for general logs
     */
    private static function createFileHandler(int $level): RotatingFileHandler {
        $handler = new RotatingFileHandler(
            self::$logPath . '/app.log',
            30, // Keep 30 days
            $level
        );
        $handler->setFormatter(self::createFormatter());
        return $handler;
    }

    /**
     * Create rotating file handler for errors only
     */
    private static function createErrorFileHandler(): RotatingFileHandler {
        $handler = new RotatingFileHandler(
            self::$logPath . '/error.log',
            90, // Keep 90 days for errors
            MonologLogger::ERROR
        );
        $handler->setFormatter(self::createFormatter());
        return $handler;
    }

    /**
     * Create log formatter
     */
    private static function createFormatter(): LineFormatter {
        // Format: [YYYY-MM-DD HH:MM:SS] channel.LEVEL: message {"context":"data"} {"extra":"data"}
        $formatter = new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
            "Y-m-d H:i:s",
            true, // Allow inline line breaks
            true  // Ignore empty context/extra
        );

        return $formatter;
    }

    /**
     * Add custom context to log records
     * Monolog 3.x processor - accepts and returns LogRecord object
     */
    public static function addCustomContext(LogRecord $record): LogRecord {
        $extra = $record->extra;

        $extra['environment'] = DEBUG ? 'development' : 'production';

        // Add user ID if authenticated
        if (isset($_SESSION['user_id'])) {
            $extra['user_id'] = $_SESSION['user_id'];
        }

        // Add request ID for tracing
        if (!isset($_SERVER['REQUEST_ID'])) {
            $_SERVER['REQUEST_ID'] = bin2hex(random_bytes(8));
        }
        $extra['request_id'] = $_SERVER['REQUEST_ID'];

        return $record->with(extra: $extra);
    }

    /**
     * Convenience methods
     */
    public static function debug(string $message, array $context = []): void {
        self::getInstance()->debug($message, $context);
    }

    public static function info(string $message, array $context = []): void {
        self::getInstance()->info($message, $context);
    }

    public static function warning(string $message, array $context = []): void {
        self::getInstance()->warning($message, $context);
    }

    public static function error(string $message, array $context = []): void {
        self::getInstance()->error($message, $context);
    }

    public static function critical(string $message, array $context = []): void {
        self::getInstance()->critical($message, $context);
    }

    /**
     * Log HTTP request
     */
    public static function logRequest(string $method, string $path, int $statusCode, float $duration): void {
        $context = [
            'method' => $method,
            'path' => $path,
            'status_code' => $statusCode,
            'duration_ms' => round($duration * 1000, 2),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ];

        if ($statusCode >= 500) {
            self::error("HTTP Request Failed", $context);
        } elseif ($statusCode >= 400) {
            self::warning("HTTP Request Error", $context);
        } else {
            self::info("HTTP Request", $context);
        }
    }

    /**
     * Log database query
     */
    public static function logQuery(string $sql, float $duration, ?array $params = null): void {
        $context = [
            'sql' => $sql,
            'duration_ms' => round($duration * 1000, 2)
        ];

        if ($params) {
            $context['params'] = $params;
        }

        if ($duration > 1.0) {
            self::warning("Slow Query Detected", $context);
        } else {
            self::debug("Database Query", $context);
        }
    }

    /**
     * Log exception with full stack trace
     */
    public static function logException(\Throwable $exception, string $message = 'Exception occurred'): void {
        self::error($message, [
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString()
        ]);
    }

    /**
     * Log security event
     */
    public static function logSecurityEvent(string $event, array $context = []): void {
        $context['event_type'] = 'security';
        $context['ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        self::warning("Security Event: {$event}", $context);
    }
}
