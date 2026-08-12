<?php

declare(strict_types=1);

namespace Emeq\HubSdk;

use Emeq\HubSdk\Contracts\ResolvesAccountId;
use Emeq\HubSdk\Http\HubConnector;
use Emeq\HubSdk\Support\HubRouteMiddleware;
use Emeq\HubSdk\Webhooks\HubWebhookProfile;
use Emeq\HubSdk\Webhooks\ProcessHubWebhookJob;
use Emeq\HubSdk\Webhooks\SpatieWebhookClientConfig;
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
                    ->endWith(function (InstallCommand $command): void {
                        $command->info('Next steps:');
                        $command->line('1. Set EMEQ_HUB_* in .env (BASE, PAT, WEBHOOK_SECRET, …)');
                        $command->line('2. Bind ResolvesAccountId (+ optional ResolvesAccountDisplayName)');
                        $command->line('3. Bind ResolvesWebhookAccount for inbound Hub webhooks');
                        $command->line('4. Route::webhooks(\'webhooks/emeq-hub\', \'emeq-hub\') + CSRF except');
                        $command->line('5. Migrate webhook_calls on the webhook DB (tenant DB if multi-DB)');
                        $command->line('6. Listen for HubConnectionRevoked / HubWebhookReceived / HubWebhookIgnored');
                        $command->line('7. Multi-DB: set hub.webhook.job (+ profile) in config/hub.php');
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
        $this->registerHubWebhookClientConfig();

        if (! (bool) config('hub.routes.enabled', false)) {
            return;
        }

        $middleware = HubRouteMiddleware::normalize(config('hub.routes.middleware'));
        HubRouteMiddleware::assertNotEmpty($middleware);

        $this->loadRoutesFrom(__DIR__.'/../routes/hub.php');
    }

    private function registerHubWebhookClientConfig(): void
    {
        /** @var Repository $config */
        $config = $this->app->make('config');

        $name = (string) $config->get('hub.webhook.name', 'emeq-hub');
        $entry = SpatieWebhookClientConfig::make(
            signingSecret: (string) $config->get('hub.webhook.secret', ''),
            profileClass: (string) $config->get('hub.webhook.profile', HubWebhookProfile::class),
            jobClass: (string) $config->get('hub.webhook.job', ProcessHubWebhookJob::class),
            name: $name,
        );

        /** @var list<array<string, mixed>> $configs */
        $configs = array_values($config->get('webhook-client.configs', []));
        $replaced = false;

        foreach ($configs as $index => $existing) {
            if (($existing['name'] ?? null) === $name) {
                $configs[$index] = $entry;
                $replaced = true;
                break;
            }
        }

        if (! $replaced) {
            $configs[] = $entry;
        }

        $config->set('webhook-client.configs', $configs);
    }
}
