<?php

namespace App\Helpers;

use App\Models\OnlineVideoChannel;
use App\Services\YoutubeService;

class OnlineVideos
{

    public static function refresh()
    {
        $logs = [];

        $youtube = new YoutubeService();

        $logs["channels"] = [];
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
                $channel->default_image = $data["snippet"]["thumbnails"]["default"]["url"];
                $channel->medium_image = $data["snippet"]["thumbnails"]["medium"]["url"];
                $channel->high_image = $data["snippet"]["thumbnails"]["high"]["url"];
                try {
                    $image_data = file_get_contents($data["snippet"]["thumbnails"]["default"]["url"]);
                    $channel->default_image_base64 = 'data:image/png;base64,' . base64_encode($image_data);
                    $channel->status = "validated";
                } catch (\Exception $e) {
                    $channel->error = $e->getMessage();
                    $channel->status = "error";
                }
            }
            $logs["channels"][] = ["channel_id" => $channel->channel_id, "status" => $channel->status];

            $channel->save();
        }
        return ["logs" => $logs];
    }
}
