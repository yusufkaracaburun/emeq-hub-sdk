<?php

declare(strict_types=1);

use Emeq\HubSdk\Http\Controllers\IntegrationController;
use Emeq\HubSdk\Support\HubRouteMiddleware;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Opt-in Hub integration BFF routes
|--------------------------------------------------------------------------
|
| Enabled via hub.routes.enabled. Middleware and prefix are consumer-config.
| Account context always comes from ResolvesAccountId — never the request.
|
| Connect / disconnect are not exposed here: mint a connect-session and send
| the user to Hub's hosted /connect page (single source of truth).
|
*/

// validated() is the only way to obtain the stack — non-empty and carrying an
// auth entry — so the middleware applied here is the middleware asserted on.
$prefix = trim((string) config('hub.routes.prefix', 'api'), '/');
$middleware = HubRouteMiddleware::validated();

Route::middleware($middleware)
    ->prefix($prefix)
    ->group(function (): void {
        Route::get('/integrations', [IntegrationController::class, 'index'])
            ->name('emeq-hub.integrations.index');
        Route::post('/integrations/connect-session', [IntegrationController::class, 'connectSession'])
            ->name('emeq-hub.integrations.connect-session');
    });
