<?php

declare(strict_types=1);

namespace Emeq\HubSdk;

use Emeq\HubSdk\Booking\AccountingChangeRecorder;
use Emeq\HubSdk\Contracts\ResolvesAccountId;
use Emeq\HubSdk\Events\HubWebhookReceived;
use Emeq\HubSdk\Http\HubConnector;
use Emeq\HubSdk\Support\HubRouteMiddleware;
use Emeq\HubSdk\Webhooks\HubWebhookProfile;
use Emeq\HubSdk\Webhooks\ProcessHubWebhookJob;
use Emeq\HubSdk\Webhooks\SpatieWebhookClientConfig;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
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
            ->hasTranslations()
            ->hasMigration('create_webhook_calls_table')
            ->hasMigration('create_hub_documents_table')
            ->hasMigration('add_trace_to_hub_documents_table')
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->endWith(function (InstallCommand $command): void {
                        $command->info('Next steps:');
                        $command->line('1. Set EMEQ_HUB_* in .env (BASE, PAT, WEBHOOK_SECRET, …)');
                        $command->line('2. Bind ResolvesAccountId (accountId + displayName)');
                        $command->line('3. Bind ResolvesWebhookAccount for inbound Hub webhooks');
                        $command->line('4. Route::webhooks(\'webhooks/emeq-hub\', \'emeq-hub\') + CSRF except');
                        $command->line('5. Migrate webhook_calls on the webhook DB (tenant DB if multi-DB)');
                        $command->line('6. Booking documents? Migrate hub_documents on the DB that holds them');
                        $command->line('   and set hub.booking.connection when that is not your default');
                        $command->line('7. Listen for HubConnectionRevoked / HubWebhookReceived / HubWebhookIgnored');
                        $command->line('8. Multi-DB: set hub.webhook.job (+ profile) in config/hub.php');
                    });
            });
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(HubConnector::class, function ($app): HubConnector {
            /** @var Repository $config */
            $config = $app->make('config');

            return new HubConnector(
                baseUrl: $config->string('hub.base_url', ''),
                pat: $config->string('hub.pat', ''),
                timeoutSeconds: $config->integer('hub.timeout', 30),
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
        $this->registerAccountingChangeRecorder();

        if (! (bool) config('hub.routes.enabled', false)) {
            return;
        }

        HubRouteMiddleware::validated();

        $this->loadRoutesFrom(__DIR__.'/../routes/hub.php');
    }

    private function registerAccountingChangeRecorder(): void
    {
        /** @var Repository $config */
        $config = $this->app->make('config');

        if (! $config->boolean('hub.booking.record_accounting_changes', true)) {
            return;
        }

        $echoWindow = $config->integer('hub.booking.echo_window_seconds', 300);

        $this->app->bind(
            AccountingChangeRecorder::class,
            static fn (): AccountingChangeRecorder => new AccountingChangeRecorder($echoWindow),
        );

        Event::listen(HubWebhookReceived::class, [AccountingChangeRecorder::class, 'handle']);
    }

    private function registerHubWebhookClientConfig(): void
    {
        /** @var Repository $config */
        $config = $this->app->make('config');

        $name = $config->string('hub.webhook.name', 'emeq-hub');
        $entry = SpatieWebhookClientConfig::make(
            signingSecret: $config->string('hub.webhook.secret', ''),
            profileClass: $config->string('hub.webhook.profile', HubWebhookProfile::class),
            jobClass: $config->string('hub.webhook.job', ProcessHubWebhookJob::class),
            name: $name,
        );

        /** @var list<array<string, mixed>> $configs */
        $configs = array_values($config->array('webhook-client.configs', []));
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

        $config->set('webhook-client.configs', $this->withoutUnprocessableEntries($configs));
    }

    /**
     * @param  list<array<string, mixed>>  $configs
     * @return list<array<string, mixed>>
     */
    private function withoutUnprocessableEntries(array $configs): array
    {
        $dropped = [];

        $kept = array_values(array_filter($configs, function (array $entry) use (&$dropped): bool {
            if (($entry['process_webhook_job'] ?? null) !== '') {
                return true;
            }

            $dropped[] = is_string($entry['name'] ?? null) ? $entry['name'] : 'unnamed';

            return false;
        }));

        if ($dropped !== []) {
            Log::info('hub.webhook.dropped_unprocessable_config', ['names' => $dropped]);
        }

        return $kept;
    }
}
