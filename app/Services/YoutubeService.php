<?php

namespace App\Services;

use GuzzleHttp\Client;

class YoutubeService
{
    protected $key;
    protected $isLocalhost;

    public function __construct()
    {
        $this->key = env('YOUTUBE_KEY');
        $this->isLocalhost = (request()->getHost() === 'localhost' || request()->getHost() === '127.0.0.1');
    }

    public function channel($id)
    {
        $client = new Client();

        try {
            $response = $client->get("https://www.googleapis.com/youtube/v3/channels", [
                'query' => [
                    'part' => 'snippet',
                    'id' => $id,
                    'key' => $this->key,
                ],
                'verify' => !$this->isLocalhost
            ]);

            $data = json_decode($response->getBody(), true);

            if (empty($data['items'])) {
                return ['error' => 'Canal não encontrado'];
            }

            $info = $data['items'][0];
            return $info;
        } catch (\Exception $e) {
            return ['error' => 'Erro ao buscar dados do canal: ' . $e->getMessage()];
        }
    }

    public function playlist($id)
    {
        $client = new Client();

        try {
            $response = $client->get("https://www.googleapis.com/youtube/v3/playlists", [
                'query' => [
                    'part' => 'snippet',
                    'id' => $id,
                    'key' => $this->key,
                ],
                'verify' => !$this->isLocalhost
            ]);

            $data = json_decode($response->getBody(), true);

            if (empty($data['items'])) {
                return ['error' => 'Playlist não encontrada'];
            }

            $info = $data['items'][0];
            return $info;
        } catch (\Exception $e) {
            return ['error' => 'Erro ao buscar dados do canal: ' . $e->getMessage()];
        }
    }

    public function playlistItems($id)
    {
        $client = new Client();
        $pageToken = null;
        $items = [];

        try {
            do {
                $response = $client->get("https://www.googleapis.com/youtube/v3/playlistItems", [
                    'query' => [
                        'part' => 'snippet',
                        'playlistId' => $id,
                        'key' => $this->key,
                        'maxResults' => 50,
                        'pageToken' => $pageToken,
                    ],
                    'verify' => !$this->isLocalhost
                ]);

                $data = json_decode($response->getBody(), true);

                if (isset($data['items'])) {
                    $items = array_merge($items, $data['items']);
                }

                $pageToken = $data['nextPageToken'] ?? null;
            } while ($pageToken);

            return $items;
        } catch (\Exception $e) {
            return ['error' => 'Erro ao buscar dados do canal: ' . $e->getMessage()];
        }
    }
}
