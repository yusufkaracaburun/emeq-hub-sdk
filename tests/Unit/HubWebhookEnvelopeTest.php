<?php

declare(strict_types=1);

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

test('envelope reads the marker for a change the consumer itself caused', function () {
    $envelope = HubWebhookEnvelope::tryFromArray([
        'event' => HubWebhookEvent::SALES_INVOICE_CHANGED->value,
        'provider' => 'exact',
        'account_id' => '42',
        'caused_by_hub' => true,
        'data' => [],
    ]);

    expect($envelope->causedByHub)->toBeTrue()
        ->and($envelope->toArray())->toHaveKey('caused_by_hub');
});

test('an absent marker reads as not caused by hub', function () {
    $envelope = HubWebhookEnvelope::tryFromArray([
        'event' => HubWebhookEvent::SALES_INVOICE_CHANGED->value,
        'provider' => 'exact',
        'account_id' => '42',
        'data' => [],
    ]);

    expect($envelope->causedByHub)->toBeFalse()
        ->and($envelope->toArray())->not->toHaveKey('caused_by_hub');
});

test('a non-boolean marker is not trusted', function () {
    $envelope = HubWebhookEnvelope::tryFromArray([
        'event' => HubWebhookEvent::SALES_INVOICE_CHANGED->value,
        'provider' => 'exact',
        'account_id' => '42',
        'caused_by_hub' => 'yes',
        'data' => [],
    ]);

    expect($envelope->causedByHub)->toBeFalse();
});

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
