<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Hub base URL
    |--------------------------------------------------------------------------
    |
    | Origin of the emeq Hub, without trailing slash. The SDK appends /v1.
    | Example: https://hub.emeq.nl
    |
    */
    'base_url' => env('EMEQ_HUB_BASE', ''),

    /*
    |--------------------------------------------------------------------------
    | Personal Access Token
    |--------------------------------------------------------------------------
    |
    | Sanctum PAT for this consumer. Keep server-side only — never ship to
    | the browser.
    |
    */
    'pat' => env('EMEQ_HUB_PAT', ''),

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('EMEQ_HUB_TIMEOUT', 30),

];
