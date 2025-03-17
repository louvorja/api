<?php

namespace App\Helpers;

use App\Models\OnlineVideoChannel;
use App\Services\YoutubeService;

class OnlineVideos
{

    public static function refresh()
    {
        $youtube = new YoutubeService();

        $channels = OnlineVideoChannel::where('status', 'pending')->get();
        foreach ($channels as $channel) {
            $data = $youtube->channel($channel->channel_id);
            if (isset($data["error"])) {
                $channel->error = $data["error"];
            } else {
                $channel->error = null;
                $channel->name = $data["snippet"]["title"];
                $channel->description = $data["snippet"]["description"];
                $channel->custom_url = $data["snippet"]["customUrl"];
                $channel->default_image = $data["snippet"]["thumbnails"]["default"]["url"];
                $channel->medium_image = $data["snippet"]["thumbnails"]["medium"]["url"];
                $channel->high_image = $data["snippet"]["thumbnails"]["high"]["url"];
                $image_data = file_get_contents($data["snippet"]["thumbnails"]["default"]["url"]);
                $channel->default_image_base64 = 'data:image/png;base64,' . base64_encode($image_data);
            }
            $channel->save();
            //            $channel->description = $youtube->channel($channel->channel_id)['snippet']['description'];

        }
        return ["logs" => "OLA MUNDO"];
    }
}
