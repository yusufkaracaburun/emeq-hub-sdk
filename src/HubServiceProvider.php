<?php

declare(strict_types=1);

namespace Emeq\HubSdk;

use Emeq\HubSdk\Contracts\ResolvesAccountId;
use Emeq\HubSdk\Http\HubConnector;
use Emeq\HubSdk\Support\HubRouteMiddleware;
use Illuminate\Contracts\Config\Repository;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class HubServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('hub')
            ->hasConfigFile('hub')
            // Publish-only — do not auto-load (multi-DB consumers pick the connection).
            ->hasMigration('create_webhook_calls_table')
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->publish('webhook-client')
                    ->endWith(function (InstallCommand $command): void {
                        $command->info('Next steps:');
                        $command->line('1. Set EMEQ_HUB_* in .env (BASE, PAT, WEBHOOK_SECRET, …)');
                        $command->line('2. Bind ResolvesAccountId (+ optional ResolvesAccountDisplayName)');
                        $command->line('3. Bind ResolvesWebhookAccount for inbound Hub webhooks');
                        $command->line('4. Route::webhooks(\'webhooks/emeq-hub\', \'emeq-hub\') + CSRF except');
                        $command->line('5. Migrate webhook_calls on the webhook DB (tenant DB if multi-DB)');
                        $command->line('6. Listen for HubConnectionRevoked / HubWebhookReceived / HubWebhookIgnored');
                        $command->line('7. Multi-DB: subclass ProcessHubWebhookJob + SerializesHubWebhookByIds');
                    });
            });
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
        if ($this->app->runningInConsole()) {
            $this->publishes([
                $this->package->basePath('/../config/webhook-client.php.stub') => config_path('webhook-client.php'),
            ], 'hub-webhook-client');
        }

        if (! (bool) config('hub.routes.enabled', false)) {
            return;
        }

        $middleware = HubRouteMiddleware::normalize(config('hub.routes.middleware'));
        HubRouteMiddleware::assertNotEmpty($middleware);

        $this->loadRoutesFrom(__DIR__.'/../routes/hub.php');
    }
}
