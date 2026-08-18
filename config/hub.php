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
    | `enabled` gates the endpoint through `HubWebhooksEnabled`, which you put
    | on the route yourself. Default open, because that is what an endpoint you
    | just registered already does. Set it to false to deploy the code before
    | `webhook_calls` exists everywhere it has to: a delivery against a missing
    | table 500s, and Hub retries a 5xx five times over roughly three hours.
    |
    | `lock_store` names the cache store used to serialize concurrent
    | redeliveries of one event id. Null uses your default store, which must
    | support atomic locks: Laravel's `database` default needs the framework's
    | cache_locks table, so point this at redis/memcached to skip that.
    |
    | `lock_seconds` is how long one delivery may hold that lock. Raise it only
    | if your listeners do slow work inside the delivery: a lock that expires
    | mid-handling lets a redelivery of the same event id run alongside it.
    |
    | Deduplication reads `webhook_calls` history to recognise an event id it
    | already handled, so `webhook-client.delete_after_days` (Spatie's default:
    | 30) sets the window in which that still works. Anything above Hub's retry
    | span — roughly three hours — is enough; cut it to hours and a late
    | redelivery outlives its own record and is processed a second time.
    |
    */
    'webhook' => [
        'enabled' => (bool) env('EMEQ_HUB_WEBHOOK_ENABLED', true),
        'secret' => env('EMEQ_HUB_WEBHOOK_SECRET', ''),
        'name' => 'emeq-hub',
        'profile' => HubWebhookProfile::class,
        'job' => ProcessHubWebhookJob::class,
        'lock_store' => env('EMEQ_HUB_WEBHOOK_LOCK_STORE'),
        'lock_seconds' => (int) env('EMEQ_HUB_WEBHOOK_LOCK_SECONDS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Booking ledger
    |--------------------------------------------------------------------------
    |
    | `hub_documents` records what this consumer sent and what Hub answered.
    | It is the authority on what may safely be sent again, so it has to sit on
    | the database that holds the documents it tracks: a ledger read against the
    | wrong connection answers "not booked yet" and the next run posts a
    | duplicate into a real administration. Null uses your default connection.
    | The backlog reads this same connection, so the documents it lists have to
    | be reachable from it.
    |
    | `lock_store` names the cache store that serializes concurrent attempts on
    | one document. Null uses your default store, which must support atomic
    | locks: Laravel's `database` default needs the framework's cache_locks
    | table, so point this at redis/memcached to skip that.
    |
    | `lock_seconds` is how long one attempt may hold that lock. It must exceed
    | `timeout` above and booking refuses to run when it does not: a lock that
    | expires mid-send lets a second attempt start alongside the first. Leave
    | room for attachment rendering on top of the timeout.
    |
    | `batch_seconds` bounds one BookingRunner batch, so a run cannot outlive
    | the request that started it. The caller gets fewer results than it asked
    | for and repeats with the remainder.
    |
    | `page_length` is the backlog's default page size when the caller names
    | none. BacklogRepository::MAX_PAGE_LENGTH is the ceiling either way.
    |
    | `record_accounting_changes` marks a posted row when an `accounting.*`
    | webhook reports the bookkeeping changed a document this app booked. That
    | marker is what the backlog's `accounting_changed` filter reads, so leaving
    | it off means the filter stays empty. Costs one indexed lookup per matching
    | delivery; set it to false if you write the columns yourself.
    |
    | `echo_window_seconds` is how long after a booking a change still counts as
    | the echo of that write rather than a human editing in the bookkeeping.
    | Lower it and every booked document gets a marker seconds later; raise it
    | and a genuine correction made just after booking goes unmarked.
    |
    */
    'booking' => [
        'connection' => env('EMEQ_HUB_BOOKING_CONNECTION'),
        'lock_store' => env('EMEQ_HUB_BOOKING_LOCK_STORE'),
        'lock_seconds' => (int) env('EMEQ_HUB_BOOKING_LOCK_SECONDS', 40),
        'batch_seconds' => (int) env('EMEQ_HUB_BOOKING_BATCH_SECONDS', 60),
        'page_length' => (int) env('EMEQ_HUB_BOOKING_PAGE_LENGTH', 25),
        'record_accounting_changes' => (bool) env('EMEQ_HUB_BOOKING_RECORD_ACCOUNTING_CHANGES', true),
        'echo_window_seconds' => (int) env('EMEQ_HUB_BOOKING_ECHO_WINDOW_SECONDS', 300),
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
