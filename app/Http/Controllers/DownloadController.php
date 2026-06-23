<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class DownloadController extends Controller
{
    public function __construct() {}

    #[OA\Get(
        path: '/download',
        summary: 'Informações de download',
        description: 'Retorna informações para download do aplicativo desktop',
        tags: ['Public'],
        security: [],
        responses: [
            new OA\Response(response: 200, description: 'Informações de download', content: new OA\JsonContent(type: 'object'))
        ]
    )]
    #[OA\Get(
        path: '/{lang}/download',
        summary: 'Informações de download (por idioma)',
        description: 'Retorna informações para download do aplicativo desktop para o idioma informado',
        tags: ['Public'],
        security: [],
        parameters: [
            new OA\Parameter(name: 'lang', description: 'Código do idioma', in: 'path', required: true, schema: new OA\Schema(type: 'string', default: 'pt'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Informações de download', content: new OA\JsonContent(type: 'object'))
        ]
    )]
    public function index(Request $request)
    {
        return response()->json([]);
    }
}
