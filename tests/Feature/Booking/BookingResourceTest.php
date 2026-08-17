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
