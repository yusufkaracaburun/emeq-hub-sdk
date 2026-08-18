<?php

declare(strict_types=1);

use Emeq\HubSdk\Booking\AccountingChangeRecorder;
use Emeq\HubSdk\Booking\HubDocument;
use Emeq\HubSdk\Events\HubWebhookReceived;
use Emeq\HubSdk\Webhooks\HubWebhookAction;
use Emeq\HubSdk\Webhooks\HubWebhookEnvelope;
use Emeq\HubSdk\Webhooks\HubWebhookEvent;
use Emeq\HubSdk\Webhooks\HubWebhookHeaders;
use Emeq\HubSdk\Webhooks\ProcessHubWebhookJob;
use Illuminate\Support\Facades\Schema;
use Spatie\WebhookClient\Models\WebhookCall;

/**
 * @param  array<string, mixed>  $attributes
 */
function recorderDocument(array $attributes = []): HubDocument
{
    return HubDocument::query()->create(array_merge([
        'account_id' => 'tenant-1',
        'type' => 'sales_invoice',
        'external_id' => 'invoice-1',
        'status' => HubDocument::STATUS_POSTED,
        'external_ref' => 'entity-1',
        'booked_at' => now()->subDay(),
    ], $attributes));
}

function recorderEnvelope(array $overrides = []): HubWebhookEnvelope
{
    return new HubWebhookEnvelope(
        event: $overrides['event'] ?? HubWebhookEvent::SALES_INVOICE_CHANGED,
        provider: 'exact',
        accountId: $overrides['accountId'] ?? 'tenant-1',
        occurredAt: $overrides['occurredAt'] ?? now()->toIso8601String(),
        data: [],
        hubAuthored: $overrides['hubAuthored'] ?? false,
        entityId: array_key_exists('entityId', $overrides) ? $overrides['entityId'] : 'entity-1',
        action: $overrides['action'] ?? HubWebhookAction::UPDATED,
        hubLastWroteAt: $overrides['hubLastWroteAt'] ?? null,
    );
}

test('a change the bookkeeping made marks the posted row', function () {
    $document = recorderDocument();

    (new AccountingChangeRecorder)->record(recorderEnvelope(), 'event-1');

    $document->refresh();

    expect($document->accounting_changed_at)->not->toBeNull()
        ->and($document->accounting_change_action)->toBe('updated')
        ->and($document->accounting_change_event_id)->toBe('event-1');
});

test('the echo of our own booking is not a change', function () {
    $document = recorderDocument(['booked_at' => now()->subSeconds(10)]);

    (new AccountingChangeRecorder)->record(recorderEnvelope(), 'event-1');

    expect($document->refresh()->accounting_changed_at)->toBeNull();
});

test('hub reporting its own write suppresses the marker outside the booking window', function () {
    $document = recorderDocument(['booked_at' => now()->subDays(30)]);

    (new AccountingChangeRecorder)->record(recorderEnvelope([
        'hubAuthored' => true,
        'hubLastWroteAt' => now()->subSeconds(5)->toIso8601String(),
    ]), 'event-1');

    expect($document->refresh()->accounting_changed_at)->toBeNull();
});

test('a change long after hub last wrote the entity still marks', function () {
    $document = recorderDocument(['booked_at' => now()->subDays(30)]);

    (new AccountingChangeRecorder)->record(recorderEnvelope([
        'hubAuthored' => true,
        'hubLastWroteAt' => now()->subDays(10)->toIso8601String(),
    ]), 'event-1');

    expect($document->refresh()->accounting_changed_at)->not->toBeNull();
});

test('another account never marks this one', function () {
    $document = recorderDocument();

    (new AccountingChangeRecorder)->record(recorderEnvelope(['accountId' => 'tenant-2']), 'event-1');

    expect($document->refresh()->accounting_changed_at)->toBeNull();
});

test('a document that was never posted is not marked', function () {
    $document = recorderDocument([
        'status' => HubDocument::STATUS_FAILED,
        'booked_at' => null,
    ]);

    (new AccountingChangeRecorder)->record(recorderEnvelope(), 'event-1');

    expect($document->refresh()->accounting_changed_at)->toBeNull();
});

test('an envelope without an entity id names no document', function () {
    $document = recorderDocument();

    (new AccountingChangeRecorder)->record(recorderEnvelope(['entityId' => null]), 'event-1');

    expect($document->refresh()->accounting_changed_at)->toBeNull();
});

test('an action hub could not map is recorded as none', function () {
    $document = recorderDocument();

    (new AccountingChangeRecorder)->record(recorderEnvelope(['action' => HubWebhookAction::UNMAPPED]), 'event-1');

    expect($document->refresh()->accounting_change_action)->toBeNull()
        ->and($document->accounting_changed_at)->not->toBeNull();
});

test('the newest posting of one entity wins', function () {
    recorderDocument(['external_id' => 'invoice-old']);
    $newest = recorderDocument(['external_id' => 'invoice-new']);

    (new AccountingChangeRecorder)->record(recorderEnvelope(), 'event-1');

    expect($newest->refresh()->accounting_changed_at)->not->toBeNull()
        ->and(HubDocument::query()->where('external_id', 'invoice-old')->first()->accounting_changed_at)->toBeNull();
});

test('a ledger without the columns is left alone rather than failing the delivery', function () {
    $document = recorderDocument();

    Schema::table((new HubDocument)->getTable(), function ($table): void {
        $table->dropColumn(['accounting_changed_at', 'accounting_change_action', 'accounting_change_event_id']);
    });
    AccountingChangeRecorder::forgetChangeSupport();

    (new AccountingChangeRecorder)->record(recorderEnvelope(), 'event-1');

    expect(Schema::hasColumn((new HubDocument)->getTable(), 'accounting_changed_at'))->toBeFalse()
        ->and($document->exists)->toBeTrue();
});

test('the listener claims the accounting change events and ignores the rest', function () {
    $document = recorderDocument();

    (new AccountingChangeRecorder)->handle(new HubWebhookReceived(
        recorderEnvelope(['event' => HubWebhookEvent::RELATION_CHANGED]),
        'event-1',
        null,
    ));

    expect($document->refresh()->accounting_changed_at)->toBeNull();

    (new AccountingChangeRecorder)->handle(new HubWebhookReceived(recorderEnvelope(), 'event-1', null));

    expect($document->refresh()->accounting_changed_at)->not->toBeNull();
});

test('the echo window is the caller\'s to widen', function () {
    $document = recorderDocument(['booked_at' => now()->subSeconds(600)]);

    (new AccountingChangeRecorder(echoWindowSeconds: 1200))->record(recorderEnvelope(), 'event-1');

    expect($document->refresh()->accounting_changed_at)->toBeNull();
});

test('the service provider wires the recorder into a real delivery', function () {
    $migration = require __DIR__.'/../../../database/migrations/create_webhook_calls_table.php.stub';
    $migration->up();

    $document = recorderDocument();

    $call = new WebhookCall;
    $call->forceFill([
        'name' => 'emeq-hub',
        'url' => 'https://consumer.test/webhooks/emeq-hub',
        'headers' => [strtolower(HubWebhookHeaders::EVENT_ID) => ['evt-9']],
        'payload' => [
            'event' => HubWebhookEvent::SALES_INVOICE_CHANGED->value,
            'provider' => 'exact',
            'account_id' => 'tenant-1',
            'occurred_at' => now()->toIso8601String(),
            'entity_id' => 'entity-1',
            'action' => 'updated',
            'data' => [],
        ],
        'exception' => null,
    ])->save();

    (new ProcessHubWebhookJob($call->fresh()))->handle();

    $document->refresh();

    expect($document->accounting_changed_at)->not->toBeNull()
        ->and($document->accounting_change_action)->toBe('updated')
        ->and($document->accounting_change_event_id)->toBe('evt-9');
});
