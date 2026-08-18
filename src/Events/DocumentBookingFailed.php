<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Events;

use Emeq\HubSdk\Booking\BookingOutcome;

final class DocumentBookingFailed
{
    public function __construct(
        public readonly string $module,
        public readonly string $id,
        public readonly BookingOutcome $outcome,
        public readonly mixed $subject = null,
    ) {}
}
