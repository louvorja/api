<?php

namespace App\Helpers;

use App\Models\OnlineVideoChannel;
use App\Models\OnlineVideoPlaylist;
use App\Services\YoutubeService;

class OnlineVideos
{

    public static function refresh()
    {
        $channels = self::refresh_channels();
        $playlists = self::refresh_playlists();

        return [
            "channels" => $channels,
            "playlists" => $playlists,
        ];
    }
    public static function refresh_channels()
    {
        $logs = [];

        $youtube = new YoutubeService();

        $channels = OnlineVideoChannel::where('status', 'pending')->get();
        foreach ($channels as $channel) {
            $data = $youtube->channel($channel->channel_id);
            if (isset($data["error"])) {
                $channel->error = $data["error"];
                $channel->status = "error";
            } else {
                $channel->error = null;
                $channel->name = $data["snippet"]["title"];
                $channel->description = $data["snippet"]["description"];
                $channel->custom_url = $data["snippet"]["customUrl"];
                $channel->default_image = $data["snippet"]["thumbnails"]["default"]["url"] ?? '';
                $channel->medium_image = $data["snippet"]["thumbnails"]["medium"]["url"] ?? '';
                $channel->high_image = $data["snippet"]["thumbnails"]["high"]["url"] ?? '';
                try {
                    if (isset($data["snippet"]["thumbnails"]["default"]["url"])) {
                        $image_data = file_get_contents($data["snippet"]["thumbnails"]["default"]["url"]);
                        $channel->default_image_base64 = 'data:image/png;base64,' . base64_encode($image_data);
                    }
                    $channel->status = "validated";
                } catch (\Exception $e) {
                    $channel->error = $e->getMessage();
                    $channel->status = "error";
                }
            }
            $logs[] = ["channel_id" => $channel->channel_id, "status" => $channel->status];

            $channel->save();
        }
        return $logs;
    }

    public static function refresh_playlists()
    {
        $channels = OnlineVideoChannel::select('id_online_video_channel', 'playlists', 'id_language')->get();
        foreach ($channels as $channel) {
            OnlineVideoPlaylist::where('id_online_video_channel', $channel->id_online_video_channel)
                ->whereNotIn('playlist_id', $channel->playlists)->delete();

            $playlists_exists = OnlineVideoPlaylist::select('playlist_id')
                ->where('id_online_video_channel', $channel->id_online_video_channel)
                ->whereIn('playlist_id', $channel->playlists)
                ->pluck('playlist_id')
                ->toArray();

            $playlists = array_diff($channel->playlists, $playlists_exists);

            foreach ($playlists as $playlist) {
                OnlineVideoPlaylist::create([
                    'id_online_video_channel' => $channel->id_online_video_channel,
                    'playlist_id' => $playlist,
                    'id_language' => $channel->id_language,
                ]);
            }
        }

        /* ------------------------------------------------------------------------------ */

        $logs = [];

        $youtube = new YoutubeService();

        $playlists = OnlineVideoPlaylist::where('status', 'pending')->get();
        foreach ($playlists as $playlist) {
            $data = $youtube->playlist($playlist->playlist_id);
            //dd($data);
            if (isset($data["error"])) {
                $playlist->error = $data["error"];
                $playlist->status = "error";
            } else {
                $playlist->error = null;
                $playlist->name = $data["snippet"]["title"];
                $playlist->description = $data["snippet"]["description"];
                $playlist->default_image = $data["snippet"]["thumbnails"]["default"]["url"] ?? '';
                $playlist->medium_image = $data["snippet"]["thumbnails"]["medium"]["url"] ?? '';
                $playlist->high_image = $data["snippet"]["thumbnails"]["high"]["url"] ?? '';
                $playlist->standard_image = $data["snippet"]["thumbnails"]["high"]["url"] ?? '';
                $playlist->maxres_image = $data["snippet"]["thumbnails"]["maxres"]["url"] ?? '';
                try {
                    if (isset($data["snippet"]["thumbnails"]["default"]["url"])) {
                        $image_data = file_get_contents($data["snippet"]["thumbnails"]["default"]["url"]);
                        $playlist->default_image_base64 = 'data:image/png;base64,' . base64_encode($image_data);
                    }
                    $playlist->status = "validated";
                } catch (\Exception $e) {
                    $playlist->error = $e->getMessage();
                    $playlist->status = "error";
                }
            }
            $logs[] = ["playlist_id" => $playlist->playlist_id, "status" => $playlist->status];

            $playlist->save();
        }
        return $logs;
    }
}
