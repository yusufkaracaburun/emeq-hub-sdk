<?php

declare(strict_types=1);

use Emeq\HubSdk\Booking\HubDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

function ledgerRow(array $attributes = []): HubDocument
{
    return HubDocument::query()->create(array_merge([
        'account_id' => 'tenant-1',
        'type' => 'sales_invoice',
        'external_id' => 'inv-1',
        'status' => HubDocument::STATUS_FAILED,
    ], $attributes));
}

it('prefers the posted row over a later attempt under a different type', function (): void {
    ledgerRow(['type' => 'sales_invoice', 'status' => HubDocument::STATUS_POSTED]);
    ledgerRow(['type' => 'credit_note', 'status' => HubDocument::STATUS_REJECTED]);

    expect(HubDocument::forBooking('inv-1', 'tenant-1')->status)->toBe(HubDocument::STATUS_POSTED)
        ->and(HubDocument::forExternalIds(['inv-1'], 'tenant-1')->get('inv-1')->status)
        ->toBe(HubDocument::STATUS_POSTED);
});

it('falls back to the most recent attempt when none is posted', function (): void {
    ledgerRow(['type' => 'sales_invoice', 'status' => HubDocument::STATUS_FAILED]);
    $latest = ledgerRow(['type' => 'credit_note', 'status' => HubDocument::STATUS_REJECTED]);

    expect(HubDocument::forBooking('inv-1', 'tenant-1')->id)->toBe($latest->id)
        ->and(HubDocument::forExternalIds(['inv-1'], 'tenant-1')->get('inv-1')->id)->toBe($latest->id);
});

it('never reads another account\'s booking', function (): void {
    ledgerRow(['account_id' => 'tenant-2', 'status' => HubDocument::STATUS_POSTED]);

    expect(HubDocument::forExternalIds(['inv-1'], 'tenant-1'))->toBeEmpty()
        ->and(HubDocument::forBooking('inv-1', 'tenant-1')->exists)->toBeFalse();
});

it('hands back an unsaved row for a document that was never attempted', function (): void {
    $record = HubDocument::forBooking('inv-9', 'tenant-1');

    expect($record->exists)->toBeFalse()
        ->and($record->account_id)->toBe('tenant-1')
        ->and($record->external_id)->toBe('inv-9')
        ->and(HubDocument::query()->count())->toBe(0);
});

it('ignores empty external ids instead of matching them', function (): void {
    ledgerRow();

    expect(HubDocument::forExternalIds(['', 'inv-1'], 'tenant-1'))->toHaveCount(1);
});

it('keeps the document number a label, not a sum', function (): void {
    $record = ledgerRow(['external_number' => 26800003]);

    expect($record->fresh()->external_number)->toBe('26800003');
});

it('follows hub.booking.connection so the ledger cannot land on the wrong database', function (): void {
    expect((new HubDocument)->getConnectionName())->toBeNull();

    config()->set('hub.booking.connection', 'tenant');

    expect((new HubDocument)->getConnectionName())->toBe('tenant');
});

it('records that the bookkeeping changed a booked document afterwards', function (): void {
    $record = ledgerRow([
        'status' => HubDocument::STATUS_POSTED,
        'accounting_changed_at' => '2026-08-17T09:00:00+00:00',
        'accounting_change_action' => 'updated',
        'accounting_change_event_id' => 'sha256:abc123',
    ]);

    $fresh = $record->fresh();

    expect($fresh->accounting_changed_at)->toBeInstanceOf(Carbon::class)
        ->and($fresh->accounting_change_action)->toBe('updated')
        ->and($fresh->accounting_change_event_id)->toBe('sha256:abc123');
});

it('indexes the lookup every accounting-change delivery performs', function (): void {
    $indexed = collect(Schema::getIndexes('hub_documents'))
        ->contains(fn (array $index): bool => $index['columns'] === ['account_id', 'external_ref']);

    expect($indexed)->toBeTrue();
});

it('re-reads trace support when one connection name swaps database', function (): void {
    $withTrace = $this->temporaryDatabase();
    $withoutTrace = $this->temporaryDatabase();

    $this->useLedgerDatabase($withTrace, withTrace: true);

    expect(HubDocument::tracesRequests())->toBeTrue();

    $this->useLedgerDatabase($withoutTrace, withTrace: false);

    expect(HubDocument::tracesRequests())->toBeFalse();
});

it('keeps writing a booking when the ledger it lands on has no trace columns', function (): void {
    $this->useLedgerDatabase($this->temporaryDatabase(), withTrace: false);

    $written = HubDocument::withoutMissingTrace([
        'status' => HubDocument::STATUS_FAILED,
        'request_id' => 'req-1',
        'category' => 'PROVIDER_ERROR',
    ]);

    expect($written)->toBe(['status' => HubDocument::STATUS_FAILED]);
});
