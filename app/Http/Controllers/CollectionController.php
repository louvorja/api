<?php

namespace App\Http\Controllers;

use App\Models\OnlineVideo;
use App\Models\OnlineVideoPlaylist;
use App\Models\OnlineVideoChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use OpenApi\Attributes as OA;

class CollectionController extends Controller
{
    #[OA\Get(
        path: '/{lang}/collections/online',
        summary: 'Coleção de conteúdo online (público)',
        description: 'Retorna canais, playlists e vídeos online estruturados, com cache de 10 minutos',
        tags: ['Public'],
        security: [],
        parameters: [
            new OA\Parameter(name: 'lang', description: 'Código do idioma', in: 'path', required: true, schema: new OA\Schema(type: 'string', default: 'pt'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Conteúdo online', content: new OA\JsonContent(type: 'object'))
        ]
    )]
    public function online(Request $request)
    {
        $cacheKey = "collections.{$request->lang}.online";

        return Cache::remember($cacheKey, 600, function () use ($request) {
            $id_language = $request->id_language;

            $channels = OnlineVideoChannel::where('id_language', $id_language)
                ->where('status', 'validated')
                ->get()
                ->map(function ($channel) {
                    return [
                        'channel_id' => $channel->channel_id,
                        'title' => $channel->title,
                        'custom_url' => $channel->custom_url,
                        'default_image' => $channel->default_image,
                        'default_image_base64' => $channel->default_image_base64,
                    ];
                });

            $playlists = OnlineVideoPlaylist::where('id_language', $id_language)
                ->where('status', 'validated')
                ->with('channel')
                ->get()
                ->map(function ($playlist) {
                    return [
                        'playlist_id' => $playlist->playlist_id,
                        'channel_id' => $playlist->channel ? $playlist->channel->channel_id : null,
                        'title' => $playlist->title,
                        'default_image' => $playlist->default_image,
                        'default_image_base64' => $playlist->default_image_base64,
                    ];
                });

            $videos = OnlineVideo::where('id_language', $id_language)
                ->where('status', 'validated')
                ->with('playlist')
                ->get()
                ->map(function ($video) {
                    return [
                        'video_id' => $video->video_id,
                        'playlist_id' => $video->playlist ? $video->playlist->playlist_id : null,
                        'title' => $video->title,
                        'sequence' => $video->sequence,
                        'default_image' => $video->default_image,
                        'default_image_base64' => $video->default_image_base64,
                    ];
                });

            return response()->json([
                'channels' => $channels,
                'playlists' => $playlists,
                'videos' => $videos,
            ]);
        });
    }
}
