<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

/**
 * API Documentation Endpoint
 * Handles: GET /api/docs
 *
 * Serves Swagger UI for interactive API documentation
 */
class requestGetDocs extends RequestBase {
    public function execute(): void {
        try {
            // Serve Swagger UI HTML
            header('Content-Type: text/html; charset=utf-8');

            echo $this->getSwaggerHtml();

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'error' => 'Documentation Error',
                'message' => DEBUG ? $e->getMessage() : 'Failed to load documentation'
            ]);
        }
    }

    /**
     * Get Swagger UI HTML
     */
    private function getSwaggerHtml(): string {
        $apiUrl = $this->getApiBaseUrl();
        $openApiSpecUrl = $apiUrl . '?r=openapi.yaml';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MBC API Documentation</title>
    <link rel="stylesheet" type="text/css" href="https://unpkg.com/swagger-ui-dist@5.10.0/swagger-ui.css">
    <style>
        html { box-sizing: border-box; overflow: -moz-scrollbars-vertical; overflow-y: scroll; }
        *, *:before, *:after { box-sizing: inherit; }
        body { margin:0; padding:0; }

        /* Custom styling */
        .swagger-ui .topbar { background-color: #2c3e50; }
        .swagger-ui .info .title { color: #2c3e50; }
    </style>
</head>
<body>
    <div id="swagger-ui"></div>

    <script src="https://unpkg.com/swagger-ui-dist@5.10.0/swagger-ui-bundle.js" charset="UTF-8"></script>
    <script src="https://unpkg.com/swagger-ui-dist@5.10.0/swagger-ui-standalone-preset.js" charset="UTF-8"></script>
    <script>
        window.onload = function() {
            const ui = SwaggerUIBundle({
                url: "{$openApiSpecUrl}",
                dom_id: '#swagger-ui',
                deepLinking: true,
                presets: [
                    SwaggerUIBundle.presets.apis,
                    SwaggerUIStandalonePreset
                ],
                plugins: [
                    SwaggerUIBundle.plugins.DownloadUrl
                ],
                layout: "StandaloneLayout",
                // Enable request/response logging
                requestInterceptor: (req) => {
                    console.log('Request:', req);
                    return req;
                },
                responseInterceptor: (res) => {
                    console.log('Response:', res);
                    return res;
                }
            });

            window.ui = ui;
        };
    </script>
</body>
</html>
HTML;
    }

    /**
     * Get API base URL
     */
    private function getApiBaseUrl(): string {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = dirname($_SERVER['SCRIPT_NAME']);

        return "{$protocol}://{$host}{$path}";
    }
}
