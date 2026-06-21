<?php

namespace App\Http\Controllers;

use OpenApi\Generator;

class OpenApiController extends Controller
{
    /**
     * Gera e serve a spec OpenAPI 3.0 JSON.
     */
    public function spec()
    {
        $openapi = Generator::scan([base_path('app')]);

        return response($openapi->toJson(), 200)
            ->header('Content-Type', 'application/json');
    }

    /**
     * Serve Swagger UI (HTML estatico com CDN).
     */
    public function ui()
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>LouvorJA API - Documentacao</title>
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
    <style>
        body { margin: 0; }
    </style>
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
    <script>
        window.onload = function() {
            window.ui = SwaggerUIBundle({
                url: '/openapi.json',
                dom_id: '#swagger-ui',
                deepLinking: true,
                presets: [SwaggerUIBundle.presets.apis],
                layout: 'BaseLayout'
            });
        };
    </script>
</body>
</html>
HTML;

        return response($html, 200)
            ->header('Content-Type', 'text/html');
    }
}
