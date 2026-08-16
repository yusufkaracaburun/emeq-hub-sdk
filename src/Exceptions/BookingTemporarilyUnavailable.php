<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The booking did not happen and nothing was decided — try again.
 *
 * Kept apart from a refusal because the two look alike on the wire but must not
 * be recorded alike: a concurrent attempt or an upstream outage says nothing
 * about the document, so writing a failed row would be a lie.
 */
class BookingTemporarilyUnavailable extends RuntimeException
{
    /**
     * @param  int|null  $retryAfter  seconds Hub asked the caller to wait, when it
     *                                said so. Carried through from
     *                                {@see HubException::$retryAfter} so a queued
     *                                retry can pace itself instead of guessing —
     *                                guessing is what turns one throttled consumer
     *                                into a throttled fleet.
     */
    public function __construct(
        string $message,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
