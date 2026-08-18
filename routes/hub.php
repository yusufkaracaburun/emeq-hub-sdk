<?php

declare(strict_types=1);

use Emeq\HubSdk\Http\Controllers\IntegrationController;
use Emeq\HubSdk\Support\HubRouteMiddleware;
use Illuminate\Support\Facades\Route;

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
