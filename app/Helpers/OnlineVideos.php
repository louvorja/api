<?php

namespace App\Helpers;

use App\Models\OnlineVideoChannel;
use App\Services\YoutubeService;

class OnlineVideos
{

    public static function refresh()
    {
        $youtube = new YoutubeService();

        $channels = OnlineVideoChannel::where('name', '')->orWhereNull('name')->get();
        foreach ($channels as $channel) {
            dd($youtube->channel($channel->id_youtube));
            print_r($channel->toArray());
        }
        return ["logs" => "OLA MUNDO"];
    }
}
