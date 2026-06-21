<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Configuration
    |--------------------------------------------------------------------------
    |
    | API-level settings previously accessed via env() at runtime.
    | Centralizing here ensures config:cache compatibility.
    |
    */

    'token' => env('API_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Multiple API Keys (optional)
    |--------------------------------------------------------------------------
    |
    | When set, validates Api-Token header against this list instead of the
    | single token above. Each key has a label (for logging) and active flag.
    | Falls back to single token when this array is empty (backward compat).
    |
    | Example:
    |   'tokens' => [
    |       'louvorja-desktop' => env('API_TOKEN_DESKTOP', ''),
    |       'louvorja-web'     => env('API_TOKEN_WEB', ''),
    |   ],
    |
    */

    'tokens' => [],
    'cache' => env('APP_CACHE', true),
    'debug' => env('APP_DEBUG', false),
    'timezone' => env('APP_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | JWT Configuration (runtime access)
    |--------------------------------------------------------------------------
    |
    | Used by FtpController and Params helper for token generation.
    |
    */

    'jwt_secret' => env('JWT_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Database (SQLite export)
    |--------------------------------------------------------------------------
    |
    | Used by DataBase helper for JSON export tasks.
    |
    */

    'sqlite_database' => env('DB_SQLITE_DATABASE', database_path('database.sqlite')),

    /*
    |--------------------------------------------------------------------------
    | External Services
    |--------------------------------------------------------------------------
    |
    */

    'cors' => [
        'allowed_origins' => env('CORS_ALLOWED_ORIGINS', '*'),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
        'chat_id' => env('TELEGRAM_CHAT_ID', ''),
    ],

    'youtube' => [
        'key' => env('YOUTUBE_KEY', ''),
    ],

];
