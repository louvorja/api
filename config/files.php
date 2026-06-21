<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Files Configuration
    |--------------------------------------------------------------------------
    |
    | File storage paths and URLs for hymnals, music, lyrics, and albums.
    | These were previously accessed via env() at runtime, which breaks
    | when `php artisan config:cache` is used.
    |
    */

    'dir' => env('FILES_DIR', '/home'),
    'url' => env('FILES_URL', 'https://localhost'),

    /*
    |--------------------------------------------------------------------------
    | SSL Verification
    |--------------------------------------------------------------------------
    |
    | Whether to verify SSL certificates on outgoing Guzzle requests.
    | Should be true in production.
    |
    */

    'ssl_verify' => env('SSL_VERIFY', false),

];
