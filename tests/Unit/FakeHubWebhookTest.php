<?php

declare(strict_types=1);

use Emeq\HubSdk\Contracts\ResolvesWebhookAccount;
use Emeq\HubSdk\Testing\FakeHubWebhook;
use Emeq\HubSdk\Webhooks\HubWebhookEnvelope;
use Emeq\HubSdk\Webhooks\HubWebhookEvent;
use Emeq\HubSdk\Webhooks\HubWebhookHeaders;
use Emeq\HubSdk\Webhooks\SpatieWebhookClientConfig;
use Illuminate\Http\Request;
use Spatie\WebhookClient\SignatureValidator\DefaultSignatureValidator;
use Spatie\WebhookClient\WebhookConfig;

beforeEach(function (): void {
    // WebhookConfig resolves webhook_profile through the container.
    $this->app->instance(ResolvesWebhookAccount::class, new class implements ResolvesWebhookAccount
    {
        public function prepare(string $accountId): bool
        {
            return true;
        }
    });
});

test('body is exactly what the envelope encodes, so a consumer can decode what they signed', function (): void {
    $fake = FakeHubWebhook::event(
        HubWebhookEvent::SALES_INVOICE_CHANGED,
        accountId: '47',
        data: ['external_ref' => 'inv-1'],
    );

    expect(HubWebhookEnvelope::tryFromRaw($fake->body())?->toArray())->toBe($fake->envelope()->toArray());
});

test('headers carry a signature the packaged validator accepts', function (): void {
    $fake = FakeHubWebhook::event(HubWebhookEvent::SALES_INVOICE_CHANGED, accountId: '47');
    $secret = 'test-secret';

    $config = new WebhookConfig(SpatieWebhookClientConfig::make($secret));
    $request = Request::create('/webhooks/emeq-hub', 'POST', content: $fake->body());
    foreach ($fake->headers($secret) as $name => $value) {
        $request->headers->set($name, $value);
    }

    expect((new DefaultSignatureValidator)->isValid($request, $config))->toBeTrue();
});

test('a body signed for one secret is rejected under another', function (): void {
    $fake = FakeHubWebhook::event(HubWebhookEvent::CONNECTION_REVOKED, accountId: '47');

    $config = new WebhookConfig(SpatieWebhookClientConfig::make('right-secret'));
    $request = Request::create('/webhooks/emeq-hub', 'POST', content: $fake->body());
    foreach ($fake->headers('wrong-secret') as $name => $value) {
        $request->headers->set($name, $value);
    }

    expect((new DefaultSignatureValidator)->isValid($request, $config))->toBeFalse();
});

test('headers carry distinct event and request ids by default', function (): void {
    $headers = FakeHubWebhook::event(HubWebhookEvent::CONNECTION_REVOKED, accountId: '47')->headers('secret');

    expect($headers[HubWebhookHeaders::EVENT_ID])->not->toBe($headers[HubWebhookHeaders::REQUEST_ID])
        ->and($headers[HubWebhookHeaders::EVENT_ID])->not->toBeEmpty();
});

test('event and request ids can be pinned for deterministic assertions', function (): void {
    $headers = FakeHubWebhook::event(
        HubWebhookEvent::CONNECTION_REVOKED,
        accountId: '47',
        eventId: 'evt-fixed',
        requestId: 'req-fixed',
    )->headers('secret');

    expect($headers[HubWebhookHeaders::EVENT_ID])->toBe('evt-fixed')
        ->and($headers[HubWebhookHeaders::REQUEST_ID])->toBe('req-fixed');
});

test('connectionRevoked builds the canonical disconnect envelope', function (): void {
    $envelope = FakeHubWebhook::connectionRevoked('47', connectionId: 'con_1')->envelope();

    expect($envelope->event)->toBe(HubWebhookEvent::CONNECTION_REVOKED)
        ->and($envelope->accountId)->toBe('47')
        ->and($envelope->data['connection_id'])->toBe('con_1');
});

test('salesInvoiceChanged builds the canonical change envelope and honours causedByHub', function (): void {
    $ownWrite = FakeHubWebhook::salesInvoiceChanged('47', causedByHub: true)->envelope();
    $externalChange = FakeHubWebhook::salesInvoiceChanged('47')->envelope();

    expect($ownWrite->event)->toBe(HubWebhookEvent::SALES_INVOICE_CHANGED)
        ->and($ownWrite->causedByHub)->toBeTrue()
        ->and($externalChange->causedByHub)->toBeFalse();
});
