<?php

namespace App\Http\Controllers;

class OpenApiController extends Controller
{
    public function spec()
    {
        $path = base_path('storage/openapi.json');

        if (!file_exists($path)) {
            return response()->json(['error' => 'openapi.json not found. Run: php generate_openapi.php'], 404);
        }

        $content = file_get_contents($path);
        return response()->json(json_decode($content), 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function ui()
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>LouvorJA API - Documentacao</title>
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
    <style>body{margin:0}</style>
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
        return response($html, 200)->header('Content-Type', 'text/html');
    }
}
