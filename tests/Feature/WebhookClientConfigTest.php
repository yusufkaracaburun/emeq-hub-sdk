<?php

declare(strict_types=1);

use Emeq\HubSdk\HubServiceProvider;
use Emeq\HubSdk\Webhooks\HubWebhookProfile;
use Emeq\HubSdk\Webhooks\ProcessHubWebhookJob;
use Illuminate\Support\Facades\Log;

test('registers hub webhook entry into webhook-client configs on boot', function () {
    $hub = collect(config('webhook-client.configs'))->firstWhere('name', 'emeq-hub');

    expect($hub)->not->toBeNull()
        ->and($hub['webhook_profile'])->toBe(HubWebhookProfile::class)
        ->and($hub['process_webhook_job'])->toBe(ProcessHubWebhookJob::class)
        ->and($hub['name'])->toBe('emeq-hub');
});

test('upserts existing emeq-hub webhook-client entry from hub.webhook', function () {
    config()->set('hub.webhook.secret', 'new-secret');
    config()->set('hub.webhook.name', 'emeq-hub');
    config()->set('hub.webhook.profile', HubWebhookProfile::class);
    config()->set('hub.webhook.job', ProcessHubWebhookJob::class);
    config()->set('webhook-client.configs', [
        [
            'name' => 'other',
            'signing_secret' => 'keep-me',
        ],
        [
            'name' => 'emeq-hub',
            'signing_secret' => 'stale',
            'webhook_profile' => HubWebhookProfile::class,
            'process_webhook_job' => ProcessHubWebhookJob::class,
        ],
    ]);

    $provider = new HubServiceProvider($this->app);
    $method = new ReflectionMethod($provider, 'registerHubWebhookClientConfig');
    $method->invoke($provider);

    $configs = config('webhook-client.configs');
    $hub = collect($configs)->firstWhere('name', 'emeq-hub');
    $other = collect($configs)->firstWhere('name', 'other');

    expect($configs)->toHaveCount(2)
        ->and($hub['signing_secret'])->toBe('new-secret')
        ->and($other['signing_secret'])->toBe('keep-me');
});

test('drops the placeholder entry Spatie merges in by default', function () {
    // WebhookClientServiceProvider::new WebhookConfig() throws InvalidConfig
    // on this exact shape — an empty process_webhook_job — so any consumer
    // who did not publish webhook-client.php 500s on the first delivery.
    Log::spy();

    config()->set('webhook-client.configs', [
        [
            'name' => 'default',
            'signing_secret' => null,
            'process_webhook_job' => '',
        ],
    ]);

    $provider = new HubServiceProvider($this->app);
    $method = new ReflectionMethod($provider, 'registerHubWebhookClientConfig');
    $method->invoke($provider);

    $configs = config('webhook-client.configs');

    expect(collect($configs)->firstWhere('name', 'default'))->toBeNull()
        ->and(collect($configs)->firstWhere('name', 'emeq-hub'))->not->toBeNull();

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => $message === 'hub.webhook.dropped_unprocessable_config'
            && $context['names'] === ['default'])
        ->once();
});

test('keeps every entry that already names a job', function () {
    config()->set('webhook-client.configs', [
        [
            'name' => 'stripe',
            'process_webhook_job' => 'App\Jobs\ProcessStripeWebhookJob',
        ],
    ]);

    $provider = new HubServiceProvider($this->app);
    $method = new ReflectionMethod($provider, 'registerHubWebhookClientConfig');
    $method->invoke($provider);

    $configs = config('webhook-client.configs');

    expect(collect($configs)->firstWhere('name', 'stripe'))->not->toBeNull();
});
