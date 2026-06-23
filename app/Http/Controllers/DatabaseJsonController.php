<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;
use OpenApi\Attributes as OA;

class DatabaseJsonController extends Controller
{
    public function __construct()
    {
        ini_set('memory_limit', '-1');
        set_time_limit(60 * 60);
    }

    /**
     * Lista todos os arquivos JSON disponiveis para download.
     * Retorna nome (sem extensao) e tamanho em bytes de cada arquivo.
     */
    #[OA\Get(
        path: '/json_db',
        summary: 'Listar arquivos JSON disponíveis',
        description: 'Retorna nome e tamanho de cada arquivo JSON na pasta public/db/json/',
        tags: ['Database'],
        security: [],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista de arquivos JSON',
                content: new OA\JsonContent(
                    type: 'object',
                    example: '{"files":[{"name":"config","size":1024}],"total":1}'
                )
            )
        ]
    )]
    public function manifest()
    {
        $dir = base_path('public/db/json/');

        if (!File::exists($dir)) {
            return response()->json([
                'files' => [],
                'total' => 0,
            ]);
        }

        $files = File::files($dir);
        $result = [];

        foreach ($files as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }

            $result[] = [
                'name' => $file->getBasename('.json'),
                'size' => $file->getSize(),
            ];
        }

        return response()->json([
            'files' => $result,
            'total' => count($result),
        ]);
    }

    #[OA\Get(
        path: '/json_db/{file}',
        summary: 'Obter arquivo JSON exportado',
        description: 'Retorna o conteudo de um arquivo JSON especifico da pasta public/db/json/',
        tags: ['Database'],
        security: [],
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
