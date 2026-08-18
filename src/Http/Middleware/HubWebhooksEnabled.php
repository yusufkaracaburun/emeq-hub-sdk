<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the inbound Hub webhook endpoint while `hub.webhook.enabled` is false.
 *
 * The flag exists for the window in which the code is deployed but
 * `webhook_calls` has not reached every database that has to hold it. With the
 * endpoint open and the table missing, each delivery 500s and Hub retries a 5xx
 * five times over roughly three hours — so the closed state has to be reachable
 * without redeploying.
 *
 * Gating here rather than around the route registration keeps the route table
 * identical in both states: flipping the flag needs no `route:cache` rebuild,
 * and a test can reach the closed state.
 *
 * Put it on the route yourself — the SDK registers no webhook route:
 *
 * ```php
 * Route::webhooks('webhooks/emeq-hub', 'emeq-hub')
 *     ->middleware(HubWebhooksEnabled::class);
 * ```
 */
class HubWebhooksEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('hub.webhook.enabled', true), 404);

        return $next($request);
    }
}
