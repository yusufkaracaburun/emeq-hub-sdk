<?php

declare(strict_types=1);

namespace Emeq\HubSdk;

use Emeq\HubSdk\Contracts\ResolvesAccountId;
use Emeq\HubSdk\Http\HubConnector;
use Emeq\HubSdk\Support\HubRouteMiddleware;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class HubServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('hub-sdk')
            ->hasConfigFile('hub');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(HubConnector::class, function ($app): HubConnector {
            /** @var Repository $config */
            $config = $app->make('config');

            return new HubConnector(
                baseUrl: (string) $config->get('hub.base_url', ''),
                pat: (string) $config->get('hub.pat', ''),
                timeoutSeconds: (int) $config->get('hub.timeout', 30),
            );
        });

        $this->app->singleton(Hub::class, function ($app): Hub {
            return new Hub(
                connector: $app->make(HubConnector::class),
                accountIdResolver: $app->bound(ResolvesAccountId::class)
                    ? $app->make(ResolvesAccountId::class)
                    : null,
            );
        });
    }

    public function packageBooted(): void
    {
        if (! (bool) config('hub.routes.enabled', false)) {
            return;
        }

        $middleware = HubRouteMiddleware::normalize(config('hub.routes.middleware'));
        HubRouteMiddleware::assertNotEmpty($middleware);

        Route::group([], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/integrations.php');
        });
    }
}
