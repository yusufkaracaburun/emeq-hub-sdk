<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HubWebhooksEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('hub.webhook.enabled', true), 404);

        return $next($request);
    }
}
