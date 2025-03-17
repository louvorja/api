<?php

namespace App\Services;

use GuzzleHttp\Client;

class YoutubeService
{
    protected $key;

    public function __construct()
    {
        $this->key = env('YOUTUBE_KEY');
    }

    public function channel($id)
    {
        $client = new Client();

        try {
            $response = $client->get("https://www.googleapis.com/youtube/v3/channels", [
                'query' => [
                    'part' => 'snippet,statistics',
                    'id' => $id,
                    'key' => $this->key,
                ],
            ]);

            $data = json_decode($response->getBody(), true);

            if (empty($data['items'])) {
                return response()->json(['error' => 'Canal não encontrado'], 404);
            }

            $channelInfo = $data['items'][0];
            return response()->json($channelInfo);
        } catch (\Exception $e) {
            // Trata erros de requisição
            return response()->json(['error' => 'Erro ao buscar dados do canal: ' . $e->getMessage()], 500);
        }
    }
}
