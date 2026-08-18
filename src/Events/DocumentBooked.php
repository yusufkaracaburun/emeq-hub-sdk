<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Events;

use Emeq\HubSdk\Booking\BookableDocument;
use Emeq\HubSdk\Booking\BookingOutcome;
use Emeq\HubSdk\Booking\BookingRunner;
use Emeq\HubSdk\Booking\Contracts\ResolvesBookableDocument;

/**
 * A document reached the bookkeeping.
 *
 * Fired by {@see BookingRunner} for a single booking and for
 * every document in a batch, so an activity log or an audit trail is written in
 * one place instead of once per call site.
 *
 * `$subject` is whatever the consumer's {@see ResolvesBookableDocument}
 * hung on the {@see BookableDocument}; null when it hung nothing.
 *
 * Read `$outcome->warnings` here: Hub reports there what it did to the relation
 * while booking, and a consumer that drops those leaves its user blind to a
 * write in their own bookkeeping.
 */
final class DocumentBooked
{
    public function __construct(
        public readonly string $module,
        public readonly string $id,
        public readonly BookingOutcome $outcome,
        public readonly mixed $subject = null,
    ) {}
}
