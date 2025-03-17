<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class YoutubeService
{
    protected $key;

    public function __construct()
    {
        $this->key = env('YOUTUBE_KEY');
    }

    public function channel($id)
    {
        $url = "https://www.googleapis.com/youtube/v3/channels?part=snippet,statistics&id=$id&key={$this->key}";
        return Http::get($url);
    }
}
