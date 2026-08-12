<?php

declare(strict_types=1);

use Emeq\HubSdk\Webhooks\HubWebhookEnvelope;
use Emeq\HubSdk\Webhooks\HubWebhookEvent;
use Emeq\HubSdk\Webhooks\HubWebhookHeaders;

test('envelope parses hub payload', function () {
    $envelope = HubWebhookEnvelope::tryFromArray([
        'event' => HubWebhookEvent::CONNECTION_REVOKED,
        'provider' => 'exact',
        'account_id' => '42',
        'occurred_at' => '2026-08-12T10:00:00+00:00',
        'data' => ['connection_id' => 'c1'],
    ]);

    expect($envelope)->not->toBeNull()
        ->and($envelope->accountId)->toBe('42')
        ->and($envelope->event)->toBe(HubWebhookEvent::CONNECTION_REVOKED)
        ->and($envelope->data['connection_id'])->toBe('c1');
});

test('envelope rejects missing account_id', function () {
    expect(HubWebhookEnvelope::tryFromArray(['event' => 'unmapped']))->toBeNull();
});

test('headers are case insensitive', function () {
    $headers = ['x-emeq-event-id' => ['evt-9']];

    expect(HubWebhookHeaders::eventId($headers))->toBe('evt-9');
});
