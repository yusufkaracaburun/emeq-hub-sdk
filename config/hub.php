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
    | `lock_store` names the cache store used to serialize concurrent
    | redeliveries of one event id. Null uses your default store, which must
    | support atomic locks: Laravel's `database` default needs the framework's
    | cache_locks table, so point this at redis/memcached to skip that.
    |
    */
    'webhook' => [
        'secret' => env('EMEQ_HUB_WEBHOOK_SECRET', ''),
        'name' => 'emeq-hub',
        'profile' => HubWebhookProfile::class,
        'job' => ProcessHubWebhookJob::class,
        'lock_store' => env('EMEQ_HUB_WEBHOOK_LOCK_STORE'),
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
    | The default throttles: these endpoints fan out to the Hub API, and
    | Laravel's `api` group carries no rate limiter unless your app opts in.
    | EMEQ_HUB_ROUTES_MIDDLEWARE is comma-separated and therefore cannot express
    | `throttle:60,1` — use a named limiter (`throttle:hub`) when overriding.
    |
    | Boot refuses a middleware stack with no `auth`-family entry. If your auth
    | middleware is named something else (`tenant.auth`), set
    | `allow_unauthenticated` to true to declare that deliberate.
    |
    */
    'routes' => [
        'enabled' => (bool) env('EMEQ_HUB_ROUTES', false),
        'prefix' => env('EMEQ_HUB_ROUTES_PREFIX', 'api'),
        'middleware' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('EMEQ_HUB_ROUTES_MIDDLEWARE', '')),
        ))) ?: ['api', 'auth:sanctum', 'throttle:60,1'],
        'allow_unauthenticated' => (bool) env('EMEQ_HUB_ROUTES_ALLOW_UNAUTHENTICATED', false),
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
