<?php
declare(strict_types=1);

date_default_timezone_set('UTC');

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load custom classes
require_once __DIR__ . '/../lib/Logger.php';

// Load environment variables using Dotenv library
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');

try {
    $dotenv->load();

    // Require essential environment variables
    $dotenv->required(['DB_DSN', 'DB_USERNAME', 'DB_PASSWORD', 'DB_PREFIX', 'JWT_SECRET']);
} catch (\Dotenv\Exception\ValidationException $e) {
    // Send proper headers before any output
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);

    if (php_sapi_name() === 'cli') {
        echo "Configuration Error: " . $e->getMessage() . "\n";
        echo "Please ensure .env file exists and contains all required variables.\n";
    } else {
        echo json_encode([
            'error' => 'Configuration Error',
            'message' => 'Server configuration is incomplete. Please contact administrator.',
            'details' => error_reporting() & E_ALL ? $e->getMessage() : null
        ], JSON_PRETTY_PRINT);
    }
    exit(1);
} catch (\Throwable $e) {
    // Catch any other errors during .env loading
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'error' => 'Configuration Error',
        'message' => '.env file could not be loaded',
        'details' => error_reporting() & E_ALL ? $e->getMessage() : null
    ], JSON_PRETTY_PRINT);
    exit(1);
}

// Set debug mode from .env or _info/ suffix
$debug = ($_ENV['DEBUG'] ?? 'false') === 'true';
if(
	substr($requestPath, -6, 6) === '_info/' &&
	$requestMethod === 'GET'
) {
	$debug = true;
	$requestPath = substr($requestPath, 0, -6);
}

// Get database configuration from environment (NO FALLBACKS - security!)
$dsn      = $_ENV['DB_DSN'];
$username = $_ENV['DB_USERNAME'];
$password = $_ENV['DB_PASSWORD'];
$prefix   = $_ENV['DB_PREFIX'];

// Connect to Database with secure settings
try {
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,  // Throw exceptions on errors
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // Fetch associative arrays by default
        PDO::ATTR_EMULATE_PREPARES   => false,                   // Use real prepared statements
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"  // UTF-8 support
    ]);
} catch (PDOException $e) {
    // Send proper headers before any output
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);

    if ($debug) {
        echo json_encode([
            'error' => 'Database Connection Failed',
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], JSON_PRETTY_PRINT);
    } else {
        echo json_encode([
            'error' => 'Database Connection Failed',
            'message' => 'Unable to connect to database. Please contact administrator.'
        ], JSON_PRETTY_PRINT);
    }
    exit(1);
}

// ----------------------------------------------------------------------------------------------

// Helper function to get SQL tables (used by request.php)
function getSqlTables(PDO $pdo, string $prefix): array {
    $sql = 'SHOW TABLES';
    $qtables = $pdo->prepare($sql);
    $qtables->execute();

    $sqlTables = [];

    while ($tables = $qtables->fetch()) {
        foreach($tables as $id => $table) {
            if ($id === 0 && str_starts_with($table, $prefix . '_')) {
                $tableName = substr($table, strlen($prefix) + 1);
                $sqlTables[$tableName] = [];

                $keysSql = 'SHOW KEYS FROM `' .  $table . '` WHERE Key_name = \'PRIMARY\'';
                $qtablesKey = $pdo->prepare($keysSql);
                $qtablesKey->execute();

                while ($tablesKey = $qtablesKey->fetch()) {
                    $sqlTables[$tableName]['key'] = $tablesKey['Column_name'];
                }
            }
        }
    }

    return $sqlTables;
}

// Get tables for legacy global access
$sqlTables = getSqlTables($pdo, $prefix);

// Debug output removed to prevent headers already sent errors
// if($debug) {
//     echo '<pre>' . print_r(array_keys($sqlTables), true) . '</pre>';
// }

// _PUT
$_PUT = json_decode(file_get_contents('php://input'), true);

global $dsn, $username, $password, $prefix, $pdo, $_PUT;

define( 'STOKEN', 42 );
define( 'PREFIX', $prefix );
define( 'DEBUG', $debug );
?>
