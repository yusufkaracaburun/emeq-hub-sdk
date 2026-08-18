<?php

declare(strict_types=1);

use Emeq\HubSdk\Booking\BookingOutcome;
use Emeq\HubSdk\Booking\HubDocument;

function ledgerRecord(array $attributes): HubDocument
{
    return new HubDocument($attributes);
}

it('reports a posted row as booked', function (): void {
    $outcome = BookingOutcome::from(ledgerRecord(['status' => HubDocument::STATUS_POSTED]));

    expect($outcome->booked)->toBeTrue()
        ->and($outcome->status)->toBe(200)
        ->and($outcome->needsManualCheck)->toBeFalse();
});

it('carries the record\'s relation warnings on a booked outcome', function (): void {
    $record = ledgerRecord(['status' => HubDocument::STATUS_POSTED]);
    $record->warnings = [['code' => 'relation.created', 'message' => 'Relatie aangemaakt.', 'context' => []]];

    $outcome = BookingOutcome::from($record);

    expect($outcome->warnings)->toBe($record->warnings);
});

it('reports no warnings for an outcome that never booked', function (): void {
    expect(BookingOutcome::unavailable()->warnings)->toBe([])
        ->and(BookingOutcome::notFound()->warnings)->toBe([]);
});

it('reports a refusal with Hub\'s own message, which names what is missing', function (): void {
    $outcome = BookingOutcome::from(ledgerRecord([
        'status' => HubDocument::STATUS_REJECTED,
        'error' => 'mapping_failed',
        'error_message' => 'Ledger account 8000 does not exist.',
    ]));

    expect($outcome->booked)->toBeFalse()
        ->and($outcome->status)->toBe(422)
        ->and($outcome->message)->toBe('Ledger account 8000 does not exist.');
});

/**
 * Reachable only for a row this package did not write — one it stores always
 * carries Hub's message. The package therefore ships no copy per error code:
 * Hub owns that, and says it better.
 */
it('falls back to the generic line when a row carries no message', function (): void {
    $outcome = BookingOutcome::from(ledgerRecord([
        'status' => HubDocument::STATUS_FAILED,
        'error' => 'provider_disabled',
    ]));

    expect($outcome->message)->toBe('The bookkeeping returned an unknown error.');
});

it('lets a consumer take back a code by publishing a key for it', function (): void {
    app('translator')->addLines(
        ['booking.error.provider_disabled' => 'De koppeling staat uit.'],
        'nl',
        'hub',
    );
    app()->setLocale('nl');

    $outcome = BookingOutcome::from(ledgerRecord([
        'status' => HubDocument::STATUS_FAILED,
        'error' => 'provider_disabled',
    ]));

    expect($outcome->message)->toBe('De koppeling staat uit.');
});

it('falls back to the generic line for an error code it does not know', function (): void {
    $outcome = BookingOutcome::from(ledgerRecord([
        'status' => HubDocument::STATUS_FAILED,
        'error' => 'something_new_on_the_hub',
    ]));

    expect($outcome->message)->toBe('The bookkeeping returned an unknown error.');
});

it('flags an interrupted send for a human instead of a retry', function (): void {
    $outcome = BookingOutcome::from(ledgerRecord([
        'status' => HubDocument::STATUS_UNKNOWN,
        'error' => 'connection_interrupted',
    ]));

    expect($outcome->needsManualCheck)->toBeTrue()
        ->and($outcome->mayRetry())->toBeFalse()
        ->and($outcome->status)->toBe(422);
});

it('marks only an undecided outcome as retryable', function (): void {
    expect(BookingOutcome::unavailable()->mayRetry())->toBeTrue()
        ->and(BookingOutcome::unavailable()->status)->toBe(503)
        ->and(BookingOutcome::upstreamFailure('Hub is down')->mayRetry())->toBeFalse()
        ->and(BookingOutcome::upstreamFailure('Hub is down')->status)->toBe(502);
});

it('ships copy for the outcomes a caller cannot phrase itself', function (): void {
    expect(BookingOutcome::notFound()->message)->toBe('This document no longer exists.')
        ->and(BookingOutcome::notFound()->status)->toBe(404)
        ->and(BookingOutcome::notAllowed()->status)->toBe(403)
        ->and(BookingOutcome::unavailable()->message)->toContain('briefly unreachable')
        ->and(BookingOutcome::notFound('Weg.')->message)->toBe('Weg.');
});

it('keeps the generic line for the user and the real cause for the log', function (): void {
    $outcome = BookingOutcome::unavailable('Upstream is down.');

    expect($outcome->message)->toContain('briefly unreachable')
        ->and($outcome->reason)->toBe('Upstream is down.');
});

it('tells a document that is already being booked apart from unreachable bookkeeping', function (): void {
    $inProgress = BookingOutcome::alreadyInProgress('Another booking attempt for inv-1 is already in progress.');

    expect($inProgress->message)->toBe('A booking of this document is already running. Wait for it to finish.')
        ->and($inProgress->message)->not->toBe(BookingOutcome::unavailable()->message)
        ->and($inProgress->status)->toBe(503)
        ->and($inProgress->mayRetry())->toBeTrue()
        ->and($inProgress->reason)->toBe('Another booking attempt for inv-1 is already in progress.');
});
