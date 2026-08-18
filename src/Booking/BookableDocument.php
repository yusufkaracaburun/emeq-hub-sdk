<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Booking;

use Closure;
use Emeq\HubSdk\Events\DocumentBooked;
use Emeq\HubSdk\Events\DocumentBookingFailed;

/**
 * A consumer document, mapped and ready to send.
 *
 * The attachment renderer stays a closure rather than a rendered array so it
 * only runs when a booking actually happens, and so a renderer that throws is
 * recorded by {@see DocumentBooker} instead of losing the attempt.
 */
final class BookableDocument
{
    /**
     * @param  array<string, mixed>  $document  canonical Hub document
     * @param  (Closure(): list<array<string, mixed>>)|null  $attachments
     * @param  mixed  $subject  the record this was mapped from, carried onto
     *                          {@see DocumentBooked} and
     *                          {@see DocumentBookingFailed} so a listener
     *                          can name it without resolving and authorising it a second time.
     *                          Untyped on purpose: this package knows nothing about your models.
     */
    public function __construct(
        public readonly array $document,
        public readonly ?Closure $attachments = null,
        public readonly mixed $subject = null,
    ) {}
}
