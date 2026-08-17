<?php

declare(strict_types=1);

use Emeq\HubSdk\Webhooks\HubWebhookAction;
use Emeq\HubSdk\Webhooks\HubWebhookEnvelope;
use Emeq\HubSdk\Webhooks\HubWebhookEvent;
use Emeq\HubSdk\Webhooks\HubWebhookHeaders;

test('envelope parses hub payload', function () {
    $envelope = HubWebhookEnvelope::tryFromArray([
        'event' => HubWebhookEvent::CONNECTION_REVOKED->value,
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

test('envelope reads the marker for an entity hub itself wrote', function () {
    $envelope = HubWebhookEnvelope::tryFromArray([
        'event' => HubWebhookEvent::SALES_INVOICE_CHANGED->value,
        'provider' => 'exact',
        'account_id' => '42',
        'hub_authored' => true,
        'data' => [],
    ]);

    expect($envelope->hubAuthored)->toBeTrue()
        ->and($envelope->toArray())->toHaveKey('hub_authored');
});

test('an absent marker reads as not authored by hub', function () {
    $envelope = HubWebhookEnvelope::tryFromArray([
        'event' => HubWebhookEvent::SALES_INVOICE_CHANGED->value,
        'provider' => 'exact',
        'account_id' => '42',
        'data' => [],
    ]);

    expect($envelope->hubAuthored)->toBeFalse()
        ->and($envelope->toArray())->not->toHaveKey('hub_authored');
});

test('a non-boolean marker is not trusted', function () {
    $envelope = HubWebhookEnvelope::tryFromArray([
        'event' => HubWebhookEvent::SALES_INVOICE_CHANGED->value,
        'provider' => 'exact',
        'account_id' => '42',
        'hub_authored' => 'yes',
        'data' => [],
    ]);

    expect($envelope->hubAuthored)->toBeFalse();
});

test('envelope carries entity id and action', function () {
    $envelope = HubWebhookEnvelope::tryFromArray([
        'event' => HubWebhookEvent::SALES_INVOICE_CHANGED->value,
        'provider' => 'exact',
        'account_id' => '42',
        'entity_id' => 'guid-1',
        'action' => 'updated',
        'data' => [],
    ]);

    expect($envelope->entityId)->toBe('guid-1')
        ->and($envelope->action)->toBe(HubWebhookAction::UPDATED)
        ->and($envelope->toArray()['action'])->toBe('updated');
});

test('an absent action stays null while an unknown one reads as unmapped', function () {
    expect(HubWebhookAction::tryFromWire(null))->toBeNull()
        ->and(HubWebhookAction::tryFromWire(''))->toBeNull()
        ->and(HubWebhookAction::tryFromWire(['updated']))->toBeNull()
        ->and(HubWebhookAction::tryFromWire('archived'))->toBe(HubWebhookAction::UNMAPPED);
});

test('a delivery inside the window after hubs own write is an echo', function () {
    $envelope = HubWebhookEnvelope::tryFromArray([
        'event' => HubWebhookEvent::SALES_INVOICE_CHANGED->value,
        'provider' => 'exact',
        'account_id' => '42',
        'hub_authored' => true,
        'hub_last_wrote_at' => '2026-08-12T10:00:00+00:00',
        'occurred_at' => '2026-08-12T10:00:04+00:00',
        'data' => [],
    ]);

    expect($envelope->isOwnEcho())->toBeTrue()
        ->and($envelope->isOwnEcho(seconds: 2))->toBeFalse();
});

test('a later change to an entity hub wrote is not an echo', function () {
    $envelope = HubWebhookEnvelope::tryFromArray([
        'event' => HubWebhookEvent::SALES_INVOICE_CHANGED->value,
        'provider' => 'exact',
        'account_id' => '42',
        'hub_authored' => true,
        'hub_last_wrote_at' => '2026-08-12T10:00:00+00:00',
        'occurred_at' => '2026-08-19T09:30:00+00:00',
        'data' => [],
    ]);

    expect($envelope->isOwnEcho())->toBeFalse();
});

test('an echo cannot be established without both facts', function (array $extra) {
    $envelope = HubWebhookEnvelope::tryFromArray([
        'event' => HubWebhookEvent::SALES_INVOICE_CHANGED->value,
        'provider' => 'exact',
        'account_id' => '42',
        'occurred_at' => '2026-08-12T10:00:04+00:00',
        'data' => [],
        ...$extra,
    ]);

    expect($envelope->isOwnEcho())->toBeFalse();
})->with([
    'no marker at all' => [[]],
    'authored but no timestamp' => [['hub_authored' => true]],
    'timestamp but not authored' => [['hub_last_wrote_at' => '2026-08-12T10:00:00+00:00']],
    'unparseable timestamp' => [['hub_authored' => true, 'hub_last_wrote_at' => 'never']],
]);

test('every event hub can send decodes to a known case', function (string $wire, HubWebhookEvent $expected) {
    expect(HubWebhookEvent::fromWire($wire))->toBe($expected);
})->with([
    ['accounting.relation.changed', HubWebhookEvent::RELATION_CHANGED],
    ['accounting.sales_invoice.changed', HubWebhookEvent::SALES_INVOICE_CHANGED],
    ['accounting.purchase_invoice.changed', HubWebhookEvent::PURCHASE_INVOICE_CHANGED],
    ['accounting.journal_entry.changed', HubWebhookEvent::JOURNAL_ENTRY_CHANGED],
    ['accounting.document.changed', HubWebhookEvent::DOCUMENT_CHANGED],
    ['accounting.ledger_account.changed', HubWebhookEvent::LEDGER_ACCOUNT_CHANGED],
    ['accounting.bank_statement.changed', HubWebhookEvent::BANK_STATEMENT_CHANGED],
    ['accounting.cash_statement.changed', HubWebhookEvent::CASH_STATEMENT_CHANGED],
]);

test('an event this release does not know reads as unmapped', function () {
    expect(HubWebhookEvent::fromWire('accounting.something.new'))->toBe(HubWebhookEvent::UNMAPPED);
});

test('envelope rejects missing account_id', function () {
    expect(HubWebhookEnvelope::tryFromArray(['event' => 'unmapped']))->toBeNull();
});

test('headers are case insensitive', function () {
    $headers = ['x-emeq-event-id' => ['evt-9']];

    expect(HubWebhookHeaders::eventId($headers))->toBe('evt-9');
});
