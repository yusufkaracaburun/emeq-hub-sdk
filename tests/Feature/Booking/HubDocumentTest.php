<?php

declare(strict_types=1);

use Emeq\HubSdk\Booking\HubDocument;

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
