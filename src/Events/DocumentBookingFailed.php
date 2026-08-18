<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Events;

use Emeq\HubSdk\Booking\BookableDocument;
use Emeq\HubSdk\Booking\BookingOutcome;
use Emeq\HubSdk\Booking\Contracts\ResolvesBookableDocument;

/**
 * A booking attempt did not post the document.
 *
 * Covers every non-posting outcome, which are not the same thing: the document
 * was refused (422), nobody was allowed to book it (403), it was not found
 * (404), Hub answered an error (502) or decided nothing at all (503). Read
 * `$outcome->status` and `$outcome->mayRetry()` rather than treating them alike
 * — only 503 says nothing about the document.
 *
 * `$outcome->needsManualCheck` marks the one case a human has to settle: the
 * send was interrupted and nobody knows whether it landed.
 *
 * `$subject` is whatever the consumer's {@see ResolvesBookableDocument}
 * hung on the {@see BookableDocument}; null when it hung nothing,
 * and null whenever resolution itself failed — there was no document to name.
 */
final class DocumentBookingFailed
{
    public function __construct(
        public readonly string $module,
        public readonly string $id,
        public readonly BookingOutcome $outcome,
        public readonly mixed $subject = null,
    ) {}
}
