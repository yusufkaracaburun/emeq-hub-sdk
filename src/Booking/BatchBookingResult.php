<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Booking;

/**
 * One document's place in a batch: which document, and what happened to it.
 */
final class BatchBookingResult
{
    public function __construct(
        public readonly string $module,
        public readonly string $id,
        public readonly BookingOutcome $outcome,
    ) {}
}
