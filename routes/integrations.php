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
*/

$prefix = trim((string) config('hub.routes.prefix', 'api'), '/');
$middleware = HubRouteMiddleware::normalize(config('hub.routes.middleware', ['api', 'auth:sanctum']));
HubRouteMiddleware::assertNotEmpty($middleware);

Route::middleware($middleware)
    ->prefix($prefix)
    ->group(function (): void {
        Route::get('/integrations', [IntegrationController::class, 'index'])
            ->name('emeq-hub.integrations.index');
        Route::post('/integrations/connect-session', [IntegrationController::class, 'connectSession'])
            ->name('emeq-hub.integrations.connect-session');
        Route::post('/integrations/{provider}/connect', [IntegrationController::class, 'connect'])
            ->name('emeq-hub.integrations.connect');
        Route::delete('/integrations/{connection}', [IntegrationController::class, 'destroy'])
            ->name('emeq-hub.integrations.destroy');
    });
