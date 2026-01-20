<?php

namespace App\Http\Controllers;

use App\Helpers\Data;
use App\Models\File;
use App\Models\Ftp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FileController extends Controller
{
    public function __construct() {}

    public function index(Request $request)
    {
        $model = new File;
        $data = $model->select();

        if (isset($request["id_album"])) {
            $data = $data
                ->whereRaw('id_file in (select id_file_image from albums where albums.id_album=' . $request["id_album"] . ')')
                ->orWhereRaw('id_file in (select id_file_image from musics inner join albums_musics on albums_musics.id_music=musics.id_music where albums_musics.id_album=' . $request["id_album"] . ')')
                ->orWhereRaw('id_file in (select id_file_music from musics inner join albums_musics on albums_musics.id_music=musics.id_music where albums_musics.id_album=' . $request["id_album"] . ')')
                ->orWhereRaw('id_file in (select id_file_instrumental_music from musics inner join albums_musics on albums_musics.id_music=musics.id_music where albums_musics.id_album=' . $request["id_album"] . ')')
                ->orWhereRaw('id_file in (select id_file_image from lyrics inner join albums_musics on albums_musics.id_music=lyrics.id_music where albums_musics.id_album=' . $request["id_album"] . ')');
        }

        return response()->json(Data::data($data, $request, [$model->getKeyName(), ...$model->getFillable()], 'files'));
    }

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

    public function open($path)
    {
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

        $replaces = [
            [],
            ['images/', 'imagens/'],
            ['musics/pt/', 'musicas/'],
            ['musics/es/', 'musicas/'],
            ['covers/', 'capas/'],
        ];


        $path = urldecode($path);

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
