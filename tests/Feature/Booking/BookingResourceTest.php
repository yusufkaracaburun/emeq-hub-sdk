<?php

declare(strict_types=1);

use Emeq\HubSdk\Booking\HubDocument;
use Emeq\HubSdk\Booking\Resources\BookingResource;

it('exposes when and how the bookkeeping changed a booked document', function (): void {
    $record = ledgerRow([
        'status' => HubDocument::STATUS_POSTED,
        'accounting_changed_at' => '2026-08-17T09:00:00+00:00',
        'accounting_change_action' => 'updated',
        'accounting_change_event_id' => 'sha256:abc123',
    ]);

    $resolved = BookingResource::make($record->fresh())->resolve();

    expect($resolved['accounting_changed_at'])->toBe('2026-08-17T09:00:00+00:00')
        ->and($resolved['accounting_change_action'])->toBe('updated')
        ->and($resolved)->not->toHaveKey('accounting_change_event_id');
});

it('reads accounting_changed_at as null for a document never reported changed', function (): void {
    $record = ledgerRow(['status' => HubDocument::STATUS_POSTED]);

    $resolved = BookingResource::make($record->fresh())->resolve();

    expect($resolved['accounting_changed_at'])->toBeNull()
        ->and($resolved['accounting_change_action'])->toBeNull();
});

/**
 * The trace has to reach a screen to be worth storing: a support question that
 * quotes the request id turns the Hub side into one lookup.
 */
it('hands the frontend the value that ties a failure to a Hub log line', function (): void {
    $record = HubDocument::query()->create([
        'account_id' => 'tenant-1',
        'type' => 'sales_invoice',
        'external_id' => 'inv-9',
        'status' => HubDocument::STATUS_FAILED,
        'error' => 'mapping_failed',
        'error_message' => "Grootboek-code '8000' niet in de mirror.",
        'category' => 'REFERENCE_MAPPING_MISSING',
        'request_id' => '01JZZ0000000000000000000RQ',
    ]);

    expect(BookingResource::maybe($record))
        ->toMatchArray([
            'request_id' => '01JZZ0000000000000000000RQ',
            'category' => 'REFERENCE_MAPPING_MISSING',
            'error' => 'mapping_failed',
        ]);
});

it('reports no trace for a row decided before the columns existed', function (): void {
    $record = HubDocument::query()->create([
        'account_id' => 'tenant-1',
        'type' => 'sales_invoice',
        'external_id' => 'inv-10',
        'status' => HubDocument::STATUS_FAILED,
        'error' => 'mapping_failed',
    ]);

    expect(BookingResource::maybe($record))
        ->toMatchArray(['request_id' => null, 'category' => null]);
});
