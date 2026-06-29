<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

class OpenApiController extends Controller
{
    #[OA\Get(
        path: '/openapi.json',
        summary: 'Especificação OpenAPI',
        description: 'Retorna a especificação OpenAPI (Swagger) em formato JSON',
        tags: ['Documentação'],
        security: [],
        responses: [
            new OA\Response(response: 200, description: 'Spec OpenAPI JSON'),
            new OA\Response(response: 404, description: 'Arquivo não encontrado')
        ]
    )]
    public function spec()
    {
        $path = base_path('storage/openapi.json');

        if (!file_exists($path)) {
            return response()->json(['error' => 'openapi.json not found. Run: php generate_openapi.php'], 404);
        }

        $spec = json_decode(file_get_contents($path), true);

        // Detectar a URL base dinamicamente do request atual.
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? '') == 443
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            ? 'https'
            : 'http';

        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $baseUrl = $protocol . '://' . $host;

        // Sempre incluir producao como server alternativo.
        // O Swagger UI mostra um dropdown para alternar entre os servers.
        // CORS ja configurado em producao (Access-Control-Allow-Origin: *).
        $spec['servers'] = [
            ['url' => $baseUrl, 'description' => 'Dev - ' . $host],
            ['url' => 'https://api.louvorja.com.br', 'description' => 'Producao - api.louvorja.com.br'],
        ];

        return response()->json($spec, 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    #[OA\Get(
        path: '/documentation',
        summary: 'Interface Swagger UI',
        description: 'Retorna a página HTML com Swagger UI para visualização interativa da documentação da API',
        tags: ['Documentação'],
        security: [],
        responses: [
            new OA\Response(response: 200, description: 'Página HTML do Swagger UI')
        ]
    )]
    public function ui()
    {
        // Detectar URL base para construir a URL absoluta da spec.
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? '') == 443
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            ? 'https'
            : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $specUrl = $protocol . '://' . $host . '/openapi.json';

        $html = <<<HTML
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
                url: '{$specUrl}',
                dom_id: '#swagger-ui',
                deepLinking: true,
                presets: [SwaggerUIBundle.presets.apis],
                layout: 'BaseLayout',
                tryItOutEnabled: true,
                supportedSubmitMethods: ['get', 'post', 'put', 'delete', 'patch'],
                docExpansion: 'list',
                filter: true,
                showExtensions: true,
                showCommonExtensions: true
            });
        };
    </script>
</body>
</html>
HTML;
        return response($html, 200)->header('Content-Type', 'text/html');
    }
}
