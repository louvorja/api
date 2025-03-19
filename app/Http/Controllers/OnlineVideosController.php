<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\OnlineVideo;
use App\Models\OnlineVideoPlaylist;
use App\Models\OnlineVideoChannel;

class OnlineVideosController extends Controller
{
    public function index(Request $request)
    {
        $id_language = strtolower($request->id_language ?? $request->query('lang') ?? "pt");

        $type = $request->query('tipo') ?? "tudo";
        $id = $request->query('id') ?? "";

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
                    "INSERT INTO ONL_VIDEOS (VIDEO_ID,PLAYLIST_ID,NOM,POSICAO,IMAGEM,IMAGEM_64) VALUES ('%s','%s','%s','%s','%s')",
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

    private function escapeString($string)
    {
        return str_replace("'", "\'", $string);
    }
}
