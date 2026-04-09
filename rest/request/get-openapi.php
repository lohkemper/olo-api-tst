<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * OpenAPI Specification Endpoint
 * Handles: GET /api/openapi.yaml
 *
 * Serves the OpenAPI 3.0 specification file
 */
class requestGetOpenapi extends RequestBase {
    public function execute(): void {
        try {
            $specFile = __DIR__ . '/../openapi.yaml';

            if (!file_exists($specFile)) {
                http_response_code(404);
                echo json_encode([
                    'error' => 'Not Found',
                    'message' => 'OpenAPI specification file not found'
                ]);
                return;
            }

            $content = file_get_contents($specFile);

            // Set appropriate headers for YAML
            header('Content-Type: application/x-yaml; charset=utf-8');
            header('Access-Control-Allow-Origin: *'); // Allow CORS for Swagger UI
            header('Cache-Control: public, max-age=3600'); // Cache for 1 hour

            echo $content;

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'error' => 'Server Error',
                'message' => DEBUG ? $e->getMessage() : 'Failed to load OpenAPI specification'
            ]);
        }
    }
}
