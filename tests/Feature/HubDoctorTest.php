<?php

declare(strict_types=1);

use Emeq\HubSdk\Contracts\ResolvesAccountId;
use Emeq\HubSdk\Contracts\ResolvesWebhookAccount;
use Emeq\HubSdk\Http\HubConnector;
use Emeq\HubSdk\Http\Request\Integrations\ListIntegrationsRequest;
use Emeq\HubSdk\Tests\Doubles\FixedAccountId;
use Illuminate\Support\Facades\Schema;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

function healthyHub(): void
{
    config()->set('hub.webhook.secret', 'shared-secret');
    config()->set('hub.booking.lock_seconds', 40);

    app()->bind(ResolvesAccountId::class, fn (): ResolvesAccountId => new FixedAccountId('tenant-1'));
    app()->bind(ResolvesWebhookAccount::class, fn (): ResolvesWebhookAccount => new class implements ResolvesWebhookAccount
    {
        public function prepare(string $accountId): bool
        {
            return true;
        }
    });

    $migration = require __DIR__.'/../../database/migrations/create_hub_documents_table.php.stub';
    $migration->up();

    $webhookCalls = require __DIR__.'/../../database/migrations/create_webhook_calls_table.php.stub';
    $webhookCalls->up();
}

it('passes on a fully wired application', function (): void {
    healthyHub();

    $this->artisan('hub:doctor')
        ->expectsOutputToContain('All checks passed.')
        ->assertSuccessful();
});

it('fails when the PAT and base url are missing', function (): void {
    healthyHub();
    config()->set('hub.base_url', '');
    config()->set('hub.pat', '');

    $this->artisan('hub:doctor')
        ->expectsOutputToContain('EMEQ_HUB_BASE')
        ->expectsOutputToContain('EMEQ_HUB_PAT')
        ->assertFailed();
});

it('fails when the base url is not an absolute URL', function (): void {
    healthyHub();
    config()->set('hub.base_url', 'hub.emeq.nl');

    $this->artisan('hub:doctor')
        ->expectsOutputToContain('is not an absolute http(s) URL')
        ->assertFailed();
});

it('fails when the booking lock would expire while a send is in flight', function (): void {
    healthyHub();
    config()->set('hub.timeout', 60);
    config()->set('hub.booking.lock_seconds', 30);

    $this->artisan('hub:doctor')
        ->expectsOutputToContain('must exceed hub.timeout')
        ->assertFailed();
});

it('fails when the webhook secret is empty while the endpoint is open', function (): void {
    healthyHub();
    config()->set('hub.webhook.secret', '');

    $this->artisan('hub:doctor')
        ->expectsOutputToContain('webhook_callback_secret')
        ->assertFailed();
});

it('warns instead of failing when the ledger was never migrated', function (): void {
    healthyHub();
    Schema::drop('hub_documents');

    $this->artisan('hub:doctor')
        ->expectsOutputToContain('Booking fails until the migration runs there')
        ->assertSuccessful();
});

it('warns when the ledger predates the trace columns', function (): void {
    healthyHub();
    Schema::table('hub_documents', function ($table): void {
        $table->dropColumn(['request_id', 'category']);
    });

    $this->artisan('hub:doctor')
        ->expectsOutputToContain('add_trace_to_hub_documents_table')
        ->assertSuccessful();
});

it('fails when nothing resolves the account id', function (): void {
    healthyHub();
    app()->forgetInstance(ResolvesAccountId::class);
    app()->offsetUnset(ResolvesAccountId::class);

    $this->artisan('hub:doctor')
        ->expectsOutputToContain('Every account-scoped Hub call needs it')
        ->assertFailed();
});

it('reaches Hub with the configured PAT when asked to ping', function (): void {
    healthyHub();

    $mock = new MockClient([
        ListIntegrationsRequest::class => MockResponse::make([
            ['key' => 'exact', 'label' => 'Exact Online', 'connectable' => true, 'status' => 'connected'],
        ], 200),
    ]);
    app(HubConnector::class)->withMockClient($mock);

    $this->artisan('hub:doctor', ['--ping' => true])
        ->expectsOutputToContain('1 integration(s) offered.')
        ->assertSuccessful();
});

it('fails the ping when Hub rejects the PAT', function (): void {
    healthyHub();

    $mock = new MockClient([
        ListIntegrationsRequest::class => MockResponse::make([
            'error' => 'unauthenticated',
            'message' => 'PAT rejected.',
            'request_id' => 'req-7',
        ], 401),
    ]);
    app(HubConnector::class)->withMockClient($mock);

    $this->artisan('hub:doctor', ['--ping' => true])
        ->expectsOutputToContain('request_id req-7')
        ->assertFailed();
});

it('fails when the cache store cannot actually take a lock', function (): void {
    healthyHub();
    config()->set('cache.default', 'database');

    $this->artisan('hub:doctor')
        ->expectsOutputToContain('Run the framework cache_locks migration')
        ->assertFailed();
});

it('still checks the lock stores when the ledger was never migrated', function (): void {
    healthyHub();
    Schema::drop('hub_documents');
    config()->set('cache.default', 'database');

    $this->artisan('hub:doctor')
        ->expectsOutputToContain('Booking fails until the migration runs there')
        ->expectsOutputToContain('hub.booking.lock_store')
        ->assertFailed();
});

it('still checks the webhook lock store when the default connection is unreachable', function (): void {
    healthyHub();
    config()->set('database.connections.broken', ['driver' => 'sqlite', 'database' => '/nonexistent/hub.sqlite']);
    config()->set('database.default', 'broken');

    $this->artisan('hub:doctor')
        ->expectsOutputToContain('hub.webhook.lock_seconds')
        ->assertFailed();
});
