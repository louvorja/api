<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Version
    |--------------------------------------------------------------------------
    |
    | API version and minimum client version for compatibility checks.
    | Clients can query GET /version to determine if they are compatible.
    |
    */

    'version' => env('API_VERSION', '1.0.0'),
    'min_client_version' => env('API_MIN_CLIENT_VERSION', '1.0.0'),

];
