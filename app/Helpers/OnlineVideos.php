<?php

namespace App\Helpers;

use App\Models\OnlineVideoChannel;
use App\Models\OnlineVideoPlaylist;
use App\Models\OnlineVideo;
use App\Services\YoutubeService;

class OnlineVideos
{

    public static function refresh()
    {
        $channels = self::refresh_channels();
        $playlists = self::refresh_playlists();
        $videos = self::refresh_videos();

        return [
            "channels" => $channels,
            "playlists" => $playlists,
            "videos" => $videos,
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
                $channel->title = $data["snippet"]["title"];
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
            if (isset($data["error"])) {
                $playlist->error = $data["error"];
                $playlist->status = "error";
            } else {
                $playlist->error = null;
                $playlist->title = $data["snippet"]["title"];
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

    public static function refresh_videos()
    {

        $logs = [];

        $youtube = new YoutubeService();

        $playlists = OnlineVideoPlaylist::get();
        foreach ($playlists as $playlist) {
            $data = $youtube->playlistItems($playlist->playlist_id);
            if (!isset($data["error"])) {
                $ids = array_map(function ($item) {
                    return $item['snippet']['resourceId']['videoId'];
                }, $data);

                OnlineVideo::where('id_online_video_playlist', $playlist->id_online_video_playlist)
                    ->whereNotIn('video_id', $ids)->delete();

                $ids_exists = OnlineVideo::select('video_id')
                    ->where('id_online_video_playlist', $playlist->id_online_video_playlist)
                    ->whereIn('video_id', $ids)
                    ->pluck('video_id')
                    ->toArray();

                $videos = array_diff($ids, $ids_exists);
                /*     foreach ($videos as $video) {
                    $video_data = array_filter($data, function ($item) use ($video) {
                        return $item['snippet']['resourceId']['videoId'] == $video;
                    });
                    $video_data = array_shift($video_data);

                    $video = new OnlineVideo();

                    $video->error = null;
                    $video->video_id = $data["snippet"]["resourceId"]["videoId"];
                    $video->title = $data["snippet"]["title"];
                    $video->description = $data["snippet"]["description"];
                    $video->default_image = $data["snippet"]["thumbnails"]["default"]["url"] ?? '';
                    $video->medium_image = $data["snippet"]["thumbnails"]["medium"]["url"] ?? '';
                    $video->high_image = $data["snippet"]["thumbnails"]["high"]["url"] ?? '';
                    $video->standard_image = $data["snippet"]["thumbnails"]["high"]["url"] ?? '';
                    $video->maxres_image = $data["snippet"]["thumbnails"]["maxres"]["url"] ?? '';
                    try {
                        if (isset($data["snippet"]["thumbnails"]["default"]["url"])) {
                            $image_data = file_get_contents($data["snippet"]["thumbnails"]["default"]["url"]);
                            $video->default_image_base64 = 'data:image/png;base64,' . base64_encode($image_data);
                        }
                        $video->status = "validated";
                    } catch (\Exception $e) {
                        $video->error = $e->getMessage();
                        $video->status = "error";
                    }
                    dd($video_data);
                    $logs[] = ["video_id" => $video->video_id, "name" => $video->name, "status" => $video->status];
                }*/
            }

            $playlist->save();
        }
        return $logs;
    }
}
