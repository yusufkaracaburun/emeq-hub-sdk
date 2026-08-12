<?php

declare(strict_types=1);

use Emeq\HubSdk\Webhooks\HubWebhookProfile;
use Emeq\HubSdk\Webhooks\ProcessHubWebhookJob;

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

    /*
    |--------------------------------------------------------------------------
    | Inbound Hub webhooks (Spatie webhook-client)
    |--------------------------------------------------------------------------
    |
    | Shared HMAC secret with Hub Consumer `webhook_callback_secret`.
    | The service provider upserts this entry into webhook-client.configs.
    | Multi-DB: override `job` / `profile` here — no separate Spatie publish.
    |
    */
    'webhook' => [
        'secret' => env('EMEQ_HUB_WEBHOOK_SECRET', ''),
        'name' => 'emeq-hub',
        'profile' => HubWebhookProfile::class,
        'job' => ProcessHubWebhookJob::class,
    ],

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
