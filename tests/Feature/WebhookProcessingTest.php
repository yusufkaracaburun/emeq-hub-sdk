<?php

declare(strict_types=1);

use Emeq\HubSdk\Contracts\ResolvesWebhookAccount;
use Emeq\HubSdk\Events\HubConnectionRevoked;
use Emeq\HubSdk\Events\HubWebhookHandled;
use Emeq\HubSdk\Events\HubWebhookIgnored;
use Emeq\HubSdk\Events\HubWebhookReceived;
use Emeq\HubSdk\Webhooks\HubWebhookDeduplicator;
use Emeq\HubSdk\Webhooks\HubWebhookEnvelope;
use Emeq\HubSdk\Webhooks\HubWebhookEvent;
use Emeq\HubSdk\Webhooks\HubWebhookHeaders;
use Emeq\HubSdk\Webhooks\HubWebhookProfile;
use Emeq\HubSdk\Webhooks\ProcessHubWebhookJob;
use Emeq\HubSdk\Webhooks\SerializesHubWebhookByIds;
use Emeq\HubSdk\Webhooks\SpatieWebhookClientConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Spatie\WebhookClient\Models\WebhookCall;
use Spatie\WebhookClient\WebhookProfile\WebhookProfile;

beforeEach(function () {
    config()->set('hub.webhook.secret', 'test-webhook-secret');

    $migration = require __DIR__.'/../../database/migrations/create_webhook_calls_table.php.stub';
    $migration->down();
    $migration->up();
});

function makeWebhookCall(array $overrides = []): WebhookCall
{
    $call = new WebhookCall;
    $call->forceFill(array_merge([
        'name' => 'emeq-hub',
        'url' => 'https://consumer.test/webhooks/emeq-hub',
        'headers' => [
            strtolower(HubWebhookHeaders::EVENT_ID) => ['evt-1'],
            strtolower(HubWebhookHeaders::REQUEST_ID) => ['req-1'],
        ],
        'payload' => [
            'event' => HubWebhookEvent::CONNECTION_REVOKED->value,
            'provider' => 'exact',
            'account_id' => '42',
            'occurred_at' => '2026-08-12T10:00:00+00:00',
            'data' => ['connection_id' => 'c1'],
        ],
        'exception' => null,
    ], $overrides));
    $call->save();

    return $call->fresh();
}

test('spatie config entry is built from its arguments, not global config', function () {
    config()->set('hub.webhook.secret', 'ignored-global');

    $entry = SpatieWebhookClientConfig::make(signingSecret: 'passed-in');

    expect($entry['signing_secret'])->toBe('passed-in')
        ->and($entry['name'])->toBe('emeq-hub')
        ->and($entry['webhook_profile'])->toBe(HubWebhookProfile::class)
        ->and($entry['process_webhook_job'])->toBe(ProcessHubWebhookJob::class)
        ->and($entry['signature_header_name'])->toBe(HubWebhookHeaders::SIGNATURE);
});

test('profile skips invalid json and missing account', function () {
    $this->app->instance(ResolvesWebhookAccount::class, new class implements ResolvesWebhookAccount
    {
        public function prepare(string $accountId): bool
        {
            return true;
        }
    });

    /** @var WebhookProfile $profile */
    $profile = $this->app->make(HubWebhookProfile::class);

    expect($profile->shouldProcess(Request::create('/', 'POST', content: '{')))->toBeFalse();
    expect($profile->shouldProcess(Request::create('/', 'POST', content: '{"event":"x"}')))->toBeFalse();
});

test('profile delegates prepare to ResolvesWebhookAccount', function () {
    $resolver = new class implements ResolvesWebhookAccount
    {
        public ?string $seen = null;

        public function prepare(string $accountId): bool
        {
            $this->seen = $accountId;

            return $accountId === '42';
        }
    };
    $this->app->instance(ResolvesWebhookAccount::class, $resolver);

    /** @var HubWebhookProfile $profile */
    $profile = $this->app->make(HubWebhookProfile::class);
    $body = json_encode([
        'event' => HubWebhookEvent::CONNECTION_REVOKED->value,
        'provider' => 'exact',
        'account_id' => '42',
        'data' => [],
    ], JSON_THROW_ON_ERROR);

    expect($profile->shouldProcess(Request::create('/', 'POST', content: $body)))->toBeTrue()
        ->and($resolver->seen)->toBe('42');
});

test('job dispatches connection revoked events', function () {
    Event::fake([HubWebhookReceived::class, HubConnectionRevoked::class, HubWebhookIgnored::class]);

    $call = makeWebhookCall();
    $job = new ProcessHubWebhookJob($call);
    $job->handle();

    Event::assertDispatched(HubWebhookReceived::class);
    Event::assertDispatched(HubConnectionRevoked::class);
    Event::assertNotDispatched(HubWebhookIgnored::class);
});

test('job dispatches ignored for other events', function () {
    Event::fake([HubWebhookReceived::class, HubConnectionRevoked::class, HubWebhookIgnored::class]);

    $call = makeWebhookCall([
        'payload' => [
            'event' => HubWebhookEvent::DOCUMENT_SYNCED->value,
            'provider' => 'exact',
            'account_id' => '42',
            'data' => [],
        ],
    ]);
    (new ProcessHubWebhookJob($call))->handle();

    Event::assertDispatched(HubWebhookReceived::class);
    Event::assertDispatched(HubWebhookIgnored::class);
    Event::assertNotDispatched(HubConnectionRevoked::class);
});

test('a subclass that declares handles() routes those events to onEvent, not onIgnored', function () {
    Event::fake([HubWebhookIgnored::class, HubWebhookHandled::class]);

    $call = makeWebhookCall([
        'payload' => [
            'event' => HubWebhookEvent::SALES_INVOICE_CHANGED->value,
            'provider' => 'exact',
            'account_id' => '42',
            'data' => [],
        ],
    ]);

    $job = new class($call) extends ProcessHubWebhookJob
    {
        protected function handles(): array
        {
            return [HubWebhookEvent::SALES_INVOICE_CHANGED];
        }
    };
    $job->handle();

    Event::assertDispatched(HubWebhookHandled::class);
    Event::assertNotDispatched(HubWebhookIgnored::class);
});

test('an event outside handles() still falls through to onIgnored', function () {
    Event::fake([HubWebhookIgnored::class, HubWebhookHandled::class]);

    $call = makeWebhookCall([
        'payload' => [
            'event' => HubWebhookEvent::DOCUMENT_SYNCED->value,
            'provider' => 'exact',
            'account_id' => '42',
            'data' => [],
        ],
    ]);

    $job = new class($call) extends ProcessHubWebhookJob
    {
        protected function handles(): array
        {
            return [HubWebhookEvent::SALES_INVOICE_CHANGED];
        }
    };
    $job->handle();

    Event::assertDispatched(HubWebhookIgnored::class);
    Event::assertNotDispatched(HubWebhookHandled::class);
});

test('connection.revoked always wins over handles(), even if a subclass claims it', function () {
    Event::fake([HubConnectionRevoked::class, HubWebhookHandled::class]);

    $call = makeWebhookCall();

    $job = new class($call) extends ProcessHubWebhookJob
    {
        protected function handles(): array
        {
            return [HubWebhookEvent::CONNECTION_REVOKED];
        }
    };
    $job->handle();

    Event::assertDispatched(HubConnectionRevoked::class);
    Event::assertNotDispatched(HubWebhookHandled::class);
});

test('onEvent defaults to logging and dispatching HubWebhookHandled', function () {
    Log::spy();
    Event::fake([HubWebhookHandled::class]);

    $call = makeWebhookCall([
        'payload' => [
            'event' => HubWebhookEvent::SALES_INVOICE_CHANGED->value,
            'provider' => 'exact',
            'account_id' => '42',
            'data' => [],
        ],
    ]);

    $job = new class($call) extends ProcessHubWebhookJob
    {
        protected function handles(): array
        {
            return [HubWebhookEvent::SALES_INVOICE_CHANGED];
        }
    };
    $job->handle();

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => $message === 'hub.webhook.handled'
            && $context['event'] === HubWebhookEvent::SALES_INVOICE_CHANGED->value)
        ->once();
});

test('default handles() keeps every existing consumer routed to onIgnored, unchanged', function () {
    Event::fake([HubWebhookIgnored::class, HubWebhookHandled::class]);

    $call = makeWebhookCall([
        'payload' => [
            'event' => HubWebhookEvent::SALES_INVOICE_CHANGED->value,
            'provider' => 'exact',
            'account_id' => '42',
            'data' => [],
        ],
    ]);

    (new ProcessHubWebhookJob($call))->handle();

    Event::assertDispatched(HubWebhookIgnored::class);
    Event::assertNotDispatched(HubWebhookHandled::class);
});

test('job deduplicates by event id', function () {
    Event::fake([HubWebhookReceived::class]);

    makeWebhookCall([
        'headers' => [strtolower(HubWebhookHeaders::EVENT_ID) => ['evt-dup']],
        'payload' => [
            'event' => HubWebhookEvent::CONNECTION_REVOKED->value,
            'provider' => 'exact',
            'account_id' => '42',
            'data' => [],
        ],
    ]);

    $second = makeWebhookCall([
        'headers' => [strtolower(HubWebhookHeaders::EVENT_ID) => ['evt-dup']],
        'payload' => [
            'event' => HubWebhookEvent::CONNECTION_REVOKED->value,
            'provider' => 'exact',
            'account_id' => '42',
            'data' => [],
        ],
    ]);

    (new ProcessHubWebhookJob($second))->handle();

    Event::assertNotDispatched(HubWebhookReceived::class);
});

test('job skips when account id empty', function () {
    Event::fake([HubWebhookReceived::class]);

    $call = makeWebhookCall([
        'payload' => [
            'event' => HubWebhookEvent::CONNECTION_REVOKED->value,
            'provider' => 'exact',
            'account_id' => '',
            'data' => [],
        ],
    ]);
    (new ProcessHubWebhookJob($call))->handle();

    Event::assertNotDispatched(HubWebhookReceived::class);
});

test('serializes hub webhook by ids round-trips', function () {
    $call = makeWebhookCall();

    $job = new class($call) extends ProcessHubWebhookJob
    {
        use SerializesHubWebhookByIds;
    };

    $payload = $job->__serialize();
    expect($payload)->not->toHaveKey('job')
        ->and($payload['accountId'])->toBe('42')
        ->and($payload['webhookCallId'])->toBe((int) $call->getKey());

    $restored = new class(new WebhookCall) extends ProcessHubWebhookJob
    {
        use SerializesHubWebhookByIds;
    };
    $restored->__unserialize($payload);

    expect($restored->accountId)->toBe('42')
        ->and($restored->webhookCallId)->toBe((int) $call->getKey())
        ->and($restored->webhookCall->id)->toBe((int) $call->getKey());
});

test('a failed job records its exception so the redelivery is not deduplicated', function () {
    Event::fake([HubWebhookReceived::class]);

    $first = makeWebhookCall([
        'headers' => [strtolower(HubWebhookHeaders::EVENT_ID) => ['evt-retry']],
    ]);

    (new ProcessHubWebhookJob($first))->failed(new RuntimeException('boom'));

    expect($first->fresh()->exception)->not->toBeNull();

    $redelivery = makeWebhookCall([
        'headers' => [strtolower(HubWebhookHeaders::EVENT_ID) => ['evt-retry']],
    ]);

    (new ProcessHubWebhookJob($redelivery))->handle();

    Event::assertDispatched(HubWebhookReceived::class);
});

test('a failure it cannot record is logged as an error, not swallowed', function () {
    Log::spy();

    $call = makeWebhookCall();

    (new class($call) extends ProcessHubWebhookJob
    {
        protected function bindAccountContext(string $accountId): bool
        {
            return false;
        }
    })->failed(new RuntimeException('boom'));

    expect($call->fresh()->exception)->toBeNull();

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => $message === 'hub.webhook.failure_unrecorded'
            && $context['reason'] === 'unknown_account_in_failed')
        ->once();
});

test('a failure with no resolvable webhook_calls row is logged as an error', function () {
    Log::spy();

    $call = makeWebhookCall();

    (new class($call) extends ProcessHubWebhookJob
    {
        protected function resolveWebhookCall(): ?WebhookCall
        {
            return null;
        }
    })->failed(new RuntimeException('boom'));

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => $message === 'hub.webhook.failure_unrecorded'
            && $context['reason'] === 'webhook_call_missing_in_failed')
        ->once();
});

test('an opaque event id identifies nothing and is never deduplicated', function () {
    Event::fake([HubWebhookReceived::class]);

    makeWebhookCall([
        'headers' => [strtolower(HubWebhookHeaders::EVENT_ID) => ['no-id']],
    ]);

    $second = makeWebhookCall([
        'headers' => [strtolower(HubWebhookHeaders::EVENT_ID) => ['no-id']],
    ]);

    (new ProcessHubWebhookJob($second))->handle();

    Event::assertDispatched(HubWebhookReceived::class);
});

test('an opaque event id takes no lock, so concurrent deliveries both process', function () {
    Event::fake([HubWebhookReceived::class]);

    $call = makeWebhookCall([
        'headers' => [strtolower(HubWebhookHeaders::EVENT_ID) => ['no-id']],
    ]);

    $held = Cache::lock('hub-webhook:emeq-hub:42:no-id', 30);
    expect($held->get())->toBeTrue();

    (new ProcessHubWebhookJob($call))->handle();

    Event::assertDispatched(HubWebhookReceived::class);

    $held->release();
});

test('a subclass can widen the opaque list', function () {
    Event::fake([HubWebhookReceived::class]);

    makeWebhookCall([
        'headers' => [strtolower(HubWebhookHeaders::EVENT_ID) => ['placeholder']],
    ]);

    $second = makeWebhookCall([
        'headers' => [strtolower(HubWebhookHeaders::EVENT_ID) => ['placeholder']],
    ]);

    (new ProcessHubWebhookJob($second))->handle();
    Event::assertNotDispatched(HubWebhookReceived::class);

    (new class($second) extends ProcessHubWebhookJob
    {
        protected function deduplicator(): HubWebhookDeduplicator
        {
            return new HubWebhookDeduplicator(
                $this->webhookConfigName(),
                $this->accountId,
                ['no-id', 'placeholder'],
            );
        }
    })->handle();

    Event::assertDispatched(HubWebhookReceived::class);
});

test('a concurrent delivery of the same event id is skipped, not raced', function () {
    Event::fake([HubWebhookReceived::class]);

    $call = makeWebhookCall([
        'headers' => [strtolower(HubWebhookHeaders::EVENT_ID) => ['evt-concurrent']],
    ]);

    $job = new ProcessHubWebhookJob($call);

    $held = Cache::lock('hub-webhook:emeq-hub:42:evt-concurrent', 30);
    expect($held->get())->toBeTrue();

    $job->handle();

    Event::assertNotDispatched(HubWebhookReceived::class);

    $held->release();
});

test('a delivery for another account is not deduplicated against the first', function () {
    Event::fake([HubWebhookReceived::class]);

    makeWebhookCall([
        'headers' => [strtolower(HubWebhookHeaders::EVENT_ID) => ['evt-shared']],
    ]);

    $other = makeWebhookCall([
        'headers' => [strtolower(HubWebhookHeaders::EVENT_ID) => ['evt-shared']],
        'payload' => [
            'event' => HubWebhookEvent::CONNECTION_REVOKED->value,
            'provider' => 'exact',
            'account_id' => '99',
            'data' => [],
        ],
    ]);

    $held = Cache::lock('hub-webhook:emeq-hub:evt-shared', 30);
    expect($held->get())->toBeTrue();

    (new ProcessHubWebhookJob($other))->handle();

    Event::assertDispatched(HubWebhookReceived::class);

    $held->release();
});

test('a numeric account id in the payload still deduplicates its redelivery', function () {
    Event::fake([HubWebhookReceived::class]);

    $payload = [
        'event' => HubWebhookEvent::CONNECTION_REVOKED->value,
        'provider' => 'exact',
        'account_id' => 42,
        'data' => [],
    ];

    makeWebhookCall([
        'headers' => [strtolower(HubWebhookHeaders::EVENT_ID) => ['evt-numeric']],
        'payload' => $payload,
    ]);

    $redelivery = makeWebhookCall([
        'headers' => [strtolower(HubWebhookHeaders::EVENT_ID) => ['evt-numeric']],
        'payload' => $payload,
    ]);

    (new ProcessHubWebhookJob($redelivery))->handle();

    Event::assertNotDispatched(HubWebhookReceived::class);
});

test('a successfully processed call still deduplicates its redelivery', function () {
    Event::fake([HubWebhookReceived::class]);

    makeWebhookCall(['headers' => [strtolower(HubWebhookHeaders::EVENT_ID) => ['evt-ok']]]);
    $redelivery = makeWebhookCall(['headers' => [strtolower(HubWebhookHeaders::EVENT_ID) => ['evt-ok']]]);

    (new ProcessHubWebhookJob($redelivery))->handle();

    Event::assertNotDispatched(HubWebhookReceived::class);
});

test('a restored by-ids job reloads the stripped webhook call and still processes', function () {
    Event::fake([HubWebhookReceived::class, HubConnectionRevoked::class]);

    $call = makeWebhookCall();

    $job = new class($call) extends ProcessHubWebhookJob
    {
        use SerializesHubWebhookByIds;
    };

    $restored = new class(new WebhookCall) extends ProcessHubWebhookJob
    {
        use SerializesHubWebhookByIds;
    };
    $restored->__unserialize($job->__serialize());

    expect($restored->webhookCall->payload)->toBeNull();

    $restored->handle();

    Event::assertDispatched(HubWebhookReceived::class);
    Event::assertDispatched(HubConnectionRevoked::class);
});

test('hub webhook event constants match hub canonical vocabulary', function () {
    $expected = [
        'accounting.bank_statement.changed',
        'accounting.cash_statement.changed',
        'accounting.relation.changed',
        'accounting.sales_invoice.changed',
        'accounting.purchase_invoice.changed',
        'accounting.journal_entry.changed',
        'accounting.document.changed',
        'accounting.ledger_account.changed',
        'accounting.document.synced',
        'billing.payment.changed',
        'billing.subscription.changed',
        'connection.revoked',
        'unmapped',
    ];

    $actual = array_map(
        static fn (HubWebhookEvent $event): string => $event->value,
        HubWebhookEvent::cases(),
    );

    expect($actual)->toBe($expected);
});

test('an event this SDK release does not know decodes to unmapped', function () {
    $envelope = HubWebhookEnvelope::tryFromArray([
        'event' => 'accounting.something.invented.later',
        'provider' => 'exact',
        'account_id' => '42',
        'data' => [],
    ]);

    expect($envelope?->event)->toBe(HubWebhookEvent::UNMAPPED);
});
