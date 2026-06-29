<?php

namespace App\Http\Controllers;

use App\Helpers\Data;
use App\Models\File;
use App\Models\Ftp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

class FileController extends Controller
{
    public function __construct() {}

    #[OA\Get(
        path: '/{lang}/files',
        summary: 'Listar arquivos (público)',
        description: 'Retorna lista paginada de arquivos para o idioma informado',
        tags: ['Public'],
        security: [],
        parameters: [
            new OA\Parameter(name: 'lang', description: 'Código do idioma', in: 'path', required: true, schema: new OA\Schema(type: 'string', default: 'pt')),
            new OA\Parameter(name: 'q', description: 'Busca textual', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'type', description: 'Tipo do arquivo', in: 'query', required: false, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista de arquivos', content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object')))
        ]
    )]
    #[OA\Get(
        path: '/admin/files',
        summary: 'Listar arquivos',
        description: 'Retorna lista paginada de arquivos, com filtros por idioma e tipo',
        tags: ['Public'],
        security: [],
        parameters: [
            new OA\Parameter(name: 'lang', description: 'Idioma', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'pt')),
            new OA\Parameter(name: 'q', description: 'Busca textual', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'type', description: 'Tipo do arquivo', in: 'query', required: false, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista de arquivos', content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object')))
        ]
    )]
    public function index(Request $request)
    {
        $model = new File;
        $data = $model->select();

        if (isset($request["id_album"])) {
            $albumId = (int) $request["id_album"];
            $data = $data
                ->whereRaw('id_file in (select id_file_image from albums where albums.id_album = ?)', [$albumId])
                ->orWhereRaw('id_file in (select id_file_music from musics inner join albums_musics on albums_musics.id_music=musics.id_music where albums_musics.id_album = ?)', [$albumId])
                ->orWhereRaw('id_file in (select id_file_instrumental_music from musics inner join albums_musics on albums_musics.id_music=musics.id_music where albums_musics.id_album = ?)', [$albumId])
                ->orWhereRaw('id_file in (select id_file_image from lyrics inner join albums_musics on albums_musics.id_music=lyrics.id_music where albums_musics.id_album = ?)', [$albumId])
                ->orWhereRaw('id_file in (select id_file_image from musics inner join albums_musics on albums_musics.id_music=musics.id_music where albums_musics.id_album = ?)', [$albumId]);
        }

        return response()->json(Data::data($data, $request, [$model->getKeyName(), ...$model->getFillable()], 'files'));
    }

    #[OA\Get(
        path: '/admin/files/{id}',
        summary: 'Buscar arquivo por ID',
        description: 'Retorna os metadados de um arquivo específico',
        tags: ['Public'],
        security: [],
        parameters: [
            new OA\Parameter(name: 'id', description: 'ID do arquivo', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dados do arquivo', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 404, description: 'Arquivo não encontrado')
        ]
    )]
    public function show($id, Request $request)
    {
        $file = File::select()->find($id);

        $data = (object) [];
        $data->data = $file;

        if (!$file) {
            return response()->json(['error' => 'Registro não encontrado!'], 404);
        }

        return response()->json($data);
    }

    #[OA\Get(
        path: '/file/{path}',
        summary: 'Abrir arquivo',
        description: 'Retorna o conteúdo ou redireciona para o arquivo no caminho informado',
        tags: ['Public'],
        security: [],
        parameters: [
            new OA\Parameter(name: 'path', description: 'Caminho do arquivo', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Conteúdo do arquivo ou redirecionamento'),
            new OA\Response(response: 404, description: 'Arquivo não encontrado')
        ]
    )]
    public function open($path)
    {
        $replaces = [
            [],
            ['images/', 'imagens/'],
            ['musics/pt/', 'musicas/'],
            ['musics/es/', 'musicas/'],
            ['covers/', 'capas/'],
        ];

        $path = urldecode($path);

        //Checa se o arquivo existe no diretório
        $exist = false;
        $original_path = $path;
        foreach ($replaces as $replace) {
            $search = $replace[0] ?? "";
            $to = $replace[1] ?? "";

            if ($search <> "") {
                $path = str_replace($search, $to, $original_path);
            }

            $path = config("files.dir") . "/" . $path;
            if (file_exists($path)) {
                $exist = true;
                break;
            }
        }

        if ($exist) {
            $mimeType = $this->getMimeType($path);
            $fileSize = filesize($path);
            $fileName = basename($path);

            // Criar stream do arquivo
            $stream = fopen($path, 'rb');

            // Retornar a resposta com os headers corretos
            return response()->stream(
                function () use ($stream) {
                    fpassthru($stream);
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                },
                200,
                [
                    'Content-Type' => $mimeType,
                    'Content-Length' => $fileSize,
                    'Content-Disposition' => 'inline; filename="' . $fileName . '"',
                    'Cache-Control' => 'public, max-age=3600',
                    'Accept-Ranges' => 'bytes',
                ]
            );
        }





        //Arquivo não existe no diretório, tenta pegar de um servidor FTP
        $path = $original_path;

        $ftp = Ftp::inRandomOrder()->first();

        if (!$ftp) {
            return response()->json([
                'error' => 'Nenhum servidor FTP disponível'
            ], 503);
        }


        $data = $ftp->data;
        $storage = Storage::build([
            'driver'   => 'ftp',
            'host'     => $data["host"],
            'username' => $data["username"],
            'password' => $data["password"],
            'root'     => ($data["root"] ?? '/') . 'config',
            'port'     => $data["port"] ?? 21,
            'passive'  => true,
            'ssl'      => false,
            'timeout'  => 30,
        ]);

        $exist = false;
        $original_path = $path;
        foreach ($replaces as $replace) {
            $search = $replace[0] ?? "";
            $to = $replace[1] ?? "";

            if ($search <> "") {
                $path = str_replace($search, $to, $original_path);
            }

            if ($storage->exists($path)) {
                $exist = true;
                break;
            }
        }

        if (!$exist) {
            return response()->json([
                'error' => 'Arquivo não encontrado!',
                'path' => $path
            ], 404);
        }


        $mimeType = $this->getMimeType($path);
        $fileSize = $storage->size($path);
        $fileName = basename($path);

        // Criar stream do arquivo
        $stream = $storage->readStream($path);

        // Retornar a resposta com os headers corretos
        return response()->stream(
            function () use ($stream) {
                fpassthru($stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            },
            200,
            [
                'Content-Type' => $mimeType,
                'Content-Length' => $fileSize,
                'Content-Disposition' => 'inline; filename="' . $fileName . '"',
                'Cache-Control' => 'public, max-age=3600',
                'Accept-Ranges' => 'bytes',
            ]
        );
    }


    private function getMimeType($path)
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $mimeTypes = [
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'm4a' => 'audio/mp4',
            'mp4' => 'video/mp4',
            'avi' => 'video/x-msvideo',
            'mov' => 'video/quicktime',
            'mkv' => 'video/x-matroska',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'bmp' => 'image/bmp',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
        ];

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }
}