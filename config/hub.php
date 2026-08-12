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
    | Inbound Hub webhooks (Spatie webhook-client)
    |--------------------------------------------------------------------------
    |
    | Shared HMAC secret with Hub Consumer `webhook_callback_secret`.
    | Wire profile/job via SpatieWebhookClientConfig::make() in
    | config/webhook-client.php — see README.
    |
    */
    'webhook_secret' => env('EMEQ_HUB_WEBHOOK_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('EMEQ_HUB_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Integration BFF routes (opt-in)
    |--------------------------------------------------------------------------
    |
    | Registers GET …/integrations and POST …/integrations/connect-session
    | under your auth middleware. Set middleware to match your app
    | (e.g. api,auth:api or api,auth:sanctum).
    |
    */
    'routes' => [
        'enabled' => (bool) env('EMEQ_HUB_ROUTES', false),
        'prefix' => env('EMEQ_HUB_ROUTES_PREFIX', 'api'),
        'middleware' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('EMEQ_HUB_ROUTES_MIDDLEWARE', 'api,auth:sanctum')),
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | OAuth return
    |--------------------------------------------------------------------------
    |
    | Path on YOUR app host that Hub redirects to after partner OAuth.
    | Built server-side as {scheme}://{host}{return_path}. Empty = omit
    | return_url (Hub falls back to the Origin of the init call).
    | Example: /settings/integrations?oauth=1
    |
    */
    'oauth' => [
        'return_path' => env('EMEQ_HUB_OAUTH_RETURN_PATH', ''),
    ],

];
