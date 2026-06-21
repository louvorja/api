<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

class DatabaseJsonController extends Controller
{
    public function __construct()
    {
        ini_set('memory_limit', '-1');
        set_time_limit(60 * 60);
    }

    #[OA\Get(
        path: '/json_db/{file}',
        summary: 'Obter arquivo JSON exportado',
        description: 'Retorna o conteudo de um arquivo JSON especifico da pasta public/db/json/',
        tags: ['Database'],
        security: [['ApiToken' => []]],
        parameters: [
            new OA\Parameter(
                name: 'file',
                description: 'Nome do arquivo (sem extensao .json)',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', example: 'config')
            )
        ],
        responses: [
            new OA\Response(response: 200, description: 'Conteudo do JSON'),
            new OA\Response(response: 404, description: 'Arquivo nao encontrado'),
        ]
    )]
    public function index($file)
    {
        $file = $file . ".json";
        $filePath = base_path('public/db/json/' . $file);

        if (!file_exists($filePath)) {
            return response()->json(['error' => 'Arquivo não encontrado!', 'path' => $filePath], 404);
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return response()->json(['error' => 'Erro ao ler o arquivo!'], 500);
        }

        return response()->json(json_decode($content, true));
    }
}
