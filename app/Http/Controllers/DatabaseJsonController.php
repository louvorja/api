<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
     * Retorna o manifest de arquivos JSON disponíveis.
     * Cache por 1 hora (3600s) para evitar leitura repetida do disco.
     */
    #[OA\Get(
        path: '/db/manifest',
        summary: 'Manifest de arquivos JSON',
        description: 'Lista todos os arquivos JSON disponíveis em public/db/json com nome, tabela e path de acesso.',
        tags: ['Database'],
        security: [['ApiToken' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista de arquivos JSON disponíveis',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'file', type: 'string', example: 'config.json'),
                            new OA\Property(property: 'table', type: 'string', example: 'config'),
                            new OA\Property(property: 'path', type: 'string', example: '/db/config'),
                            new OA\Property(property: 'hash', type: 'string', description: 'MD5 hash do arquivo para cache ETag', example: 'a1b2c3d4e5f6...'),
                        ]
                    )
                )
            )
        ]
    )]
    public function manifest()
    {
        return Cache::remember('db.manifest', 3600, function () {
            $jsonDir = base_path('public/db/json');
            $files = [];
            if (File::exists($jsonDir)) {
                foreach (File::files($jsonDir) as $file) {
                    $filename = $file->getFilename();
                    $hash = \App\Helpers\GenerateStaticJsons::getHash($filename);
                    $files[] = [
                        'file' => $filename,
                        'table' => $file->getFilenameWithoutExtension(),
                        'path' => '/db/' . $file->getFilenameWithoutExtension(),
                        'hash' => $hash,
                    ];
                }
            }
            return $files;
        });
    }

    /**
     * Retorna todos os registros de uma tabela (arquivo JSON).
     * Suporta paginação via ?page=1&per_page=50
     * Cache por 5 minutos (300s) por página.
     */
    #[OA\Get(
        path: '/db/{table}',
        summary: 'Registros de uma tabela JSON',
        description: 'Retorna registros paginados de um arquivo JSON específico. Suporta paginação via query params.',
        tags: ['Database'],
        security: [['ApiToken' => []]],
        parameters: [
            new OA\Parameter(
                name: 'table',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string'),
                description: 'Nome da tabela (arquivo JSON sem extensão)'
            ),
            new OA\Parameter(
                name: 'page',
                in: 'query',
                schema: new OA\Schema(type: 'integer', default: 1),
                description: 'Número da página'
            ),
            new OA\Parameter(
                name: 'per_page',
                in: 'query',
                schema: new OA\Schema(type: 'integer', default: 50),
                description: 'Itens por página'
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Registros paginados',
                headers: [
                    new OA\Header(header: 'ETag', description: 'MD5 hash do arquivo para cache condicional', schema: new OA\Schema(type: 'string')),
                ],
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(type: 'object')
                        ),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'total', type: 'integer', example: 100),
                                new OA\Property(property: 'per_page', type: 'integer', example: 50),
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'last_page', type: 'integer', example: 2),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 304, description: 'Não modificado (ETag coincide com If-None-Match)'),
            new OA\Response(response: 404, description: 'Arquivo não encontrado')
        ]
    )]
    public function table(Request $request, string $table)
    {
        $jsonDir = base_path('public/db/json');
        $filename = "{$table}.json";
        $filePath = "{$jsonDir}/{$filename}";

        if (!File::exists($filePath)) {
            return response()->json(['error' => 'Table not found'], 404);
        }

        // ETag cache condicional: se o hash do arquivo bate com If-None-Match, retorna 304
        $hash = \App\Helpers\GenerateStaticJsons::getHash($filename);
        if ($hash && $request->header('If-None-Match') === $hash) {
            return response('', 304);
        }

        $data = json_decode(File::get($filePath), true);

        // Extrai campo 'data' se existir como array (JSONs gerados pelo GenerateStaticJsons
        // possuem envelope _meta + data). Remove _meta e usa data diretamente.
        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        $perPage = (int) $request->get('per_page', 50);
        $page = (int) $request->get('page', 1);
        $total = count($data);
        $offset = ($page - 1) * $perPage;
        $items = array_slice($data, $offset, $perPage);

        $response = response()->json([
            'data' => $items,
            'meta' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => (int) ceil($total / $perPage),
            ]
        ]);

        if ($hash) {
            $response->header('ETag', $hash);
        }

        return $response;
    }

    /**
     * Bundle ZIP de todos os arquivos JSON.
     * Gera um ZIP em memória com todos os .json de public/db/json.
     * Cache por 1 hora (3600s).
     */
    #[OA\Get(
        path: '/db/bundle',
        summary: 'Bundle ZIP de todos os JSON',
        description: 'Gera e baixa um arquivo ZIP contendo todos os arquivos JSON da pasta public/db/json.',
        tags: ['Database'],
        security: [['ApiToken' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Arquivo ZIP',
                content: new OA\MediaType(mediaType: 'application/zip')
            ),
            new OA\Response(response: 404, description: 'Nenhum arquivo JSON encontrado')
        ]
    )]
    public function bundle()
    {
        $cacheKey = 'db.bundle.zip';

        $zipContent = Cache::remember($cacheKey, 3600, function () {
            $jsonDir = base_path('public/db/json');

            if (!File::exists($jsonDir)) {
                return null;
            }

            $files = File::files($jsonDir);
            $jsonFiles = array_filter($files, fn ($f) => $f->getExtension() === 'json');

            if (empty($jsonFiles)) {
                return null;
            }

            $zip = new \ZipArchive();
            $tempFile = tempnam(sys_get_temp_dir(), 'louvorja_bundle_');
            $zip->open($tempFile, \ZipArchive::OVERWRITE);

            foreach ($jsonFiles as $file) {
                $zip->addFile($file->getPathname(), $file->getFilename());
            }

            $zip->close();
            $content = file_get_contents($tempFile);
            unlink($tempFile);

            return $content;
        });

        if ($zipContent === null) {
            return response()->json(['error' => 'Nenhum arquivo JSON encontrado'], 404);
        }

        return response($zipContent, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="louvorja-db-bundle.zip"',
            'Content-Length' => strlen($zipContent),
        ]);
    }

    /**
     * Exporta categorias únicas de uma tabela/coluna.
     * Cache por 10 minutos (600s).
     */
    #[OA\Get(
        path: '/db/{table}/categories',
        summary: 'Categorias únicas de uma coluna',
        description: 'Retorna array de valores únicos de uma coluna específica em um arquivo JSON, ordenados alfabeticamente.',
        tags: ['Database'],
        security: [['ApiToken' => []]],
        parameters: [
            new OA\Parameter(
                name: 'table',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string'),
                description: 'Nome da tabela'
            ),
            new OA\Parameter(
                name: 'column',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string'),
                description: 'Nome da coluna para extrair categorias únicas'
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Array de categorias únicas ordenadas',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(type: 'string')
                )
            ),
            new OA\Response(response: 400, description: 'Parâmetro column obrigatório'),
            new OA\Response(response: 404, description: 'Tabela não encontrada')
        ]
    )]
    public function categories(Request $request, string $table)
    {
        $column = $request->get('column');
        if (!$column) {
            return response()->json(['error' => 'Parâmetro column é obrigatório'], 400);
        }

        $cacheKey = "db.categories.{$table}.column.{$column}";

        return Cache::remember($cacheKey, 600, function () use ($table, $column) {
            $jsonDir = base_path('public/db/json');
            $filePath = "{$jsonDir}/{$table}.json";

            if (!File::exists($filePath)) {
                return response()->json(['error' => 'Table not found'], 404);
            }

            $data = json_decode(File::get($filePath), true);

            $categories = [];
            foreach ($data as $row) {
                if (isset($row[$column]) && $row[$column] !== null && $row[$column] !== '') {
                    $categories[$row[$column]] = true;
                }
            }

            $categories = array_keys($categories);
            sort($categories);

            return response()->json($categories);
        });
    }

    /**
     * Obter arquivo JSON exportado (endpoint legado).
     */
    #[OA\Get(
        path: '/json_db/{file}',
        summary: 'Obter arquivo JSON exportado (legado)',
        description: 'Retorna o conteúdo de um arquivo JSON específico da pasta public/db/json/',
        tags: ['Database'],
        security: [],
        parameters: [
            new OA\Parameter(
                name: 'file',
                description: 'Nome do arquivo (sem extensão .json)',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', example: 'config')
            )
        ],
        responses: [
            new OA\Response(response: 200, description: 'Conteúdo do JSON'),
            new OA\Response(response: 404, description: 'Arquivo não encontrado'),
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

    /**
     * Recria todos os arquivos JSON estaticos gerados do banco.
     * Chama DataBase::export_json() para gerar pt_categories.json, pt_musics.json, etc.
     */
    #[OA\Get(
        path: '/db/export',
        summary: 'Recriar JSONs do banco',
        description: 'Recria todos os arquivos JSON estáticos gerados a partir do banco de dados.',
        tags: ['Database'],
        security: [['ApiToken' => []]],
        responses: [
            new OA\Response(response: 200, description: 'JSONs recriados com sucesso'),
            new OA\Response(response: 500, description: 'Erro ao recriar arquivos'),
        ]
    )]
    public function export()
    {
        try {
            $logs = \App\Helpers\DataBase::export_json();
            return response()->json([
                'status' => 'success',
                'message' => 'Arquivos JSON recriados com sucesso',
                'logs' => $logs,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erro ao recriar arquivos JSON',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
