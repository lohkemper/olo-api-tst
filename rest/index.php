<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set("display_errors", 1);

// === CORS FIX START ===
$allowedOrigins = ['http://localhost:4200', 'http://localhost:4201', 'https://oliverlohkemper.de', 'https://www.oliverlohkemper.de'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token, X-Requested-With, Accept');
    http_response_code(200);
    exit(0);
}
// === CORS FIX END ===



global $_PUT;
$_PUT = array();
$requestPath = $_REQUEST['r'];
if(substr($requestPath,0,1) == '/') {
  $requestPath = substr($requestPath, 1);
}
$requestMethod = $_SERVER['REQUEST_METHOD'];

include('cfg/cfg.php');
include('auth/session-helper.php');
include('request/request.php');

// Start secure session
SessionHelper::start();

// Start request timer for performance monitoring
$requestStartTime = microtime(true);

// Log incoming request
Logger::info('Incoming Request', [
    'method' => $requestMethod,
    'path' => $requestPath,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
]);

// var_dump( [ $requestPath, $requestMethod, $debug ] );

$request = new Request();

$request->setMethode($requestMethod);

$request->setPath(explode('/', $requestPath));

// Pass query parameters (filters like is_active, page, etc.)
$queryParams = $_GET;
unset($queryParams['r']); // Remove the route parameter
$request->setQueryParams($queryParams);

$request->setPdo( $pdo );

try {
    $request->execute();

    // Log successful request
    $duration = microtime(true) - $requestStartTime;
    Logger::logRequest($requestMethod, $requestPath, http_response_code() ?: 200, $duration);
} catch (\Throwable $e) {
    // Log exception
    Logger::logException($e, 'Unhandled exception in request processing');

    // Log failed request
    $duration = microtime(true) - $requestStartTime;
    Logger::logRequest($requestMethod, $requestPath, 500, $duration);

    // Send error response
    http_response_code(500);
    if (DEBUG) {
        echo json_encode([
            'error' => 'Internal Server Error',
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    } else {
        echo json_encode([
            'error' => 'Internal Server Error',
            'message' => 'An unexpected error occurred'
        ]);
    }
}

// Close SQL connection
$pdo = null;
?>
