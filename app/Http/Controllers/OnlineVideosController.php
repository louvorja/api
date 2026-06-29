<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\OnlineVideo;
use App\Models\OnlineVideoPlaylist;
use App\Models\OnlineVideoChannel;
use OpenApi\Attributes as OA;

class OnlineVideosController extends Controller
{
    #[OA\Get(
        path: '/onlinevideos',
        summary: 'Listar vídeos online',
        description: 'Retorna canais, playlists e vídeos online em formato SQL ou JSON. O formato SQL usa pipe (|) como separador e é compatível com o app desktop legado.',
        tags: ['API'],
        security: [],
        parameters: [
            new OA\Parameter(name: 'lang', description: 'Idioma', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'pt')),
            new OA\Parameter(name: 'tipo', description: 'Tipo de conteúdo', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['tudo', 'canais', 'playlists', 'videos'], default: 'tudo')),
            new OA\Parameter(name: 'id', description: 'ID do canal ou playlist para filtrar', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'format', description: 'Formato de resposta', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['sql', 'json'], default: 'sql'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Canais, playlists e vídeos (formato depende do parâmetro format)', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'channels', type: 'array', items: new OA\Items(type: 'object', properties: [
                        new OA\Property(property: 'channel_id', type: 'string'),
                        new OA\Property(property: 'title', type: 'string'),
                        new OA\Property(property: 'custom_url', type: 'string'),
                        new OA\Property(property: 'default_image', type: 'string'),
                        new OA\Property(property: 'default_image_base64', type: 'string')
                    ])),
                    new OA\Property(property: 'playlists', type: 'array', items: new OA\Items(type: 'object', properties: [
                        new OA\Property(property: 'playlist_id', type: 'string'),
                        new OA\Property(property: 'channel_id', type: 'string'),
                        new OA\Property(property: 'title', type: 'string'),
                        new OA\Property(property: 'default_image', type: 'string'),
                        new OA\Property(property: 'default_image_base64', type: 'string')
                    ])),
                    new OA\Property(property: 'videos', type: 'array', items: new OA\Items(type: 'object', properties: [
                        new OA\Property(property: 'video_id', type: 'string'),
                        new OA\Property(property: 'playlist_id', type: 'string'),
                        new OA\Property(property: 'title', type: 'string'),
                        new OA\Property(property: 'sequence', type: 'string'),
                        new OA\Property(property: 'default_image', type: 'string'),
                        new OA\Property(property: 'default_image_base64', type: 'string')
                    ]))
                ]
            ))
        ]
    )]
    public function index(Request $request)
    {
        $id_language = strtolower($request->id_language ?? $request->query('lang') ?? "pt");
        if ($id_language == "") {
            $id_language = "pt";
        }

        $type = $request->query('tipo') ?? "tudo";
        $id = $request->query('id') ?? "";
        $format = $request->query('format') ?? "sql"; // sql ou json

        // SQL format (default for backward compatibility)
        if ($format === "sql") {
            return $this->returnSqlFormat($id_language, $type, $id);
        }

        // JSON format (modern, easier to parse)
        return $this->returnJsonFormat($id_language, $type, $id);
    }

    private function returnSqlFormat($id_language, $type, $id)
    {
        $sql = [];

        // SQL CANAIS
        if ($type == "canais" || $type == "tudo") {
            $sql[] = "DELETE FROM ONL_CANAIS";

            $channels = OnlineVideoChannel::where('id_language', $id_language)->where('status', 'validated')->get();
            foreach ($channels as $channel) {
                $sql[] = sprintf(
                    "INSERT INTO ONL_CANAIS (CANAL_ID,NOME,CUSTOM_URL,IMAGEM,IMAGEM_64) VALUES ('%s','%s','%s','%s','%s')",
                    $this->escapeString($channel->channel_id),
                    $this->escapeString($channel->title),
                    $this->escapeString($channel->custom_url),
                    $this->escapeString($channel->default_image),
                    $this->escapeString($channel->default_image_base64)
                );
            }
        }

        // SQL PLAYLISTS
        if ($type == "playlists" || $type == "tudo") {
            $sql[] = sprintf("DELETE FROM ONL_PLAYLISTS %s", $id ? "WHERE CANAL_ID = '$id'" : "");

            $playlists = OnlineVideoPlaylist::where('id_language', $id_language)->where('status', 'validated');
            if ($id <> "") {
                $playlists->with('channel')->whereHas('channel', function ($query) use ($id) {
                    $query->where('channel_id', $id);
                });
            }
            $playlists = $playlists->get();
            foreach ($playlists as $playlist) {
                $sql[] = sprintf(
                    "INSERT INTO ONL_PLAYLISTS (PLAYLIST_ID,CANAL_ID,NOME,IMAGEM,IMAGEM_64) VALUES ('%s','%s','%s','%s','%s')",
                    $this->escapeString($playlist->playlist_id),
                    $this->escapeString($playlist->channel->channel_id),
                    $this->escapeString($playlist->title),
                    $this->escapeString($playlist->default_image),
                    $this->escapeString($playlist->default_image_base64)
                );
            }
        }

        // SQL VIDEOS
        if ($type == "videos" || $type == "tudo") {
            $sql[] = sprintf("DELETE FROM ONL_VIDEOS %s", $id ? "WHERE PLAYLIST_ID = '$id'" : "");

            $videos = OnlineVideo::where('id_language', $id_language)->where('status', 'validated');
            if ($id <> "") {
                $videos->with('playlist')->whereHas('playlist', function ($query) use ($id) {
                    $query->where('playlist_id', $id);
                });
            }
            $videos = $videos->get();
            foreach ($videos as $video) {
                $sql[] = sprintf(
                    "INSERT INTO ONL_VIDEOS (VIDEO_ID,PLAYLIST_ID,NOME,POSICAO,IMAGEM,IMAGEM_64) VALUES ('%s','%s','%s','%s','%s','%s')",
                    $this->escapeString($video->video_id),
                    $this->escapeString($video->playlist->playlist_id),
                    $this->escapeString($video->title),
                    $this->escapeString($video->sequence),
                    $this->escapeString($video->default_image),
                    $this->escapeString($video->default_image_base64)
                );
            }
        }

        return implode("|", $sql) . PHP_EOL;
    }

    private function returnJsonFormat($id_language, $type, $id)
    {
        $data = [];

        // CANAIS
        if ($type == "canais" || $type == "tudo") {
            $data['channels'] = OnlineVideoChannel::where('id_language', $id_language)->where('status', 'validated')
                ->get()
                ->map(function ($channel) {
                    return [
                        'channel_id' => $channel->channel_id,
                        'title' => $channel->title,
                        'custom_url' => $channel->custom_url,
                        'default_image' => $channel->default_image,
                        'default_image_base64' => $channel->default_image_base64,
                    ];
                })->toArray();
        }

        // PLAYLISTS
        if ($type == "playlists" || $type == "tudo") {
            $playlists = OnlineVideoPlaylist::where('id_language', $id_language)->where('status', 'validated');
            if ($id <> "") {
                $playlists->with('channel')->whereHas('channel', function ($query) use ($id) {
                    $query->where('channel_id', $id);
                });
            }
            $data['playlists'] = $playlists->get()->map(function ($playlist) {
                return [
                    'playlist_id' => $playlist->playlist_id,
                    'channel_id' => $playlist->channel->channel_id,
                    'title' => $playlist->title,
                    'default_image' => $playlist->default_image,
                    'default_image_base64' => $playlist->default_image_base64,
                ];
            })->toArray();
        }

        // VIDEOS
        if ($type == "videos" || $type == "tudo") {
            $videos = OnlineVideo::where('id_language', $id_language)->where('status', 'validated');
            if ($id <> "") {
                $videos->with('playlist')->whereHas('playlist', function ($query) use ($id) {
                    $query->where('playlist_id', $id);
                });
            }
            $data['videos'] = $videos->get()->map(function ($video) {
                return [
                    'video_id' => $video->video_id,
                    'playlist_id' => $video->playlist->playlist_id,
                    'title' => $video->title,
                    'sequence' => $video->sequence,
                    'default_image' => $video->default_image,
                    'default_image_base64' => $video->default_image_base64,
                ];
            })->toArray();
        }

        return response()->json($data);
    }

    private function escapeString($string)
    {
        return str_replace("'", "\\'", $string);
    }
}
