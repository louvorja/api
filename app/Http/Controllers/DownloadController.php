<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class DownloadController extends Controller
{
    public function __construct() {}

    #[OA\Get(
        path: '/download',
        summary: 'Download do app desktop',
        description: 'Redireciona (302) para a URL de download do instalador do app desktop LouvorJA. A URL é obtida dinamicamente dos parâmetros do sistema conforme o idioma. ⚠️ O "Try it out" do Swagger pode falhar porque o redirect vai para github.com (CORS do browser bloqueia). Teste direto no navegador ou via curl.',
        tags: ['Public'],
        security: [],
        parameters: [
            new OA\Parameter(name: 'lang', description: 'Código do idioma (default: pt)', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'pt'))
        ],
        responses: [
            new OA\Response(response: 302, description: 'Redirecionamento para a URL de download', headers: [
                new OA\Header(header: 'Location', description: 'URL de download', schema: new OA\Schema(type: 'string', format: 'url'))
            ]),
            new OA\Response(response: 404, description: 'URL de download não configurada para o idioma')
        ]
    )]
    #[OA\Get(
        path: '/{lang}/download',
        summary: 'Download do app desktop (por idioma)',
        description: 'Redireciona (302) para a URL de download do instalador do app desktop LouvorJA para o idioma informado. ⚠️ O "Try it out" do Swagger pode falhar porque o redirect vai para github.com (CORS do browser bloqueia). Teste direto no navegador ou via curl.',
        tags: ['Public'],
        security: [],
        parameters: [
            new OA\Parameter(name: 'lang', description: 'Código do idioma', in: 'path', required: true, schema: new OA\Schema(type: 'string', default: 'pt'))
        ],
        responses: [
            new OA\Response(response: 302, description: 'Redirecionamento para a URL de download', headers: [
                new OA\Header(header: 'Location', description: 'URL de download', schema: new OA\Schema(type: 'string', format: 'url'))
            ]),
            new OA\Response(response: 404, description: 'URL de download não configurada para o idioma')
        ]
    )]
    public function index(Request $request)
    {
        $id_language = strtolower($request->id_language ?? $request->query('lang') ?? 'pt');
        $params = \App\Helpers\Params::all();

        $url = $params[$id_language . '_download'] ?? $params['pt_download'] ?? null;

        if (!$url) {
            return response()->json(['error' => 'URL de download não configurada'], 404);
        }

        \App\Models\DownloadLog::create([
            'version'     => $params[$id_language . '_version'] ?? null,
            'id_language' => $id_language,
        ]);

        return redirect($url);
    }
}
