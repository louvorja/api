<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class TelegramService
{
    protected $botToken;
    protected $chatId;

    public function __construct()
    {
        $this->botToken = config('api.telegram.bot_token');
        $this->chatId = config('api.telegram.chat_id');
    }

    public function sendMessage($message)
    {
        $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";
        $data = [
            'chat_id' => $this->chatId,
            'text' => $message,
            'parse_mode' => 'HTML'
        ];

        Http::withOptions([
            'verify' => config('files.ssl_verify'),
        ])->post($url, $data);
    }
}
