<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Exceptions;

use RuntimeException;

/**
 * The booking did not happen and nothing was decided — try again.
 *
 * Kept apart from a refusal because the two look alike on the wire but must not
 * be recorded alike: a concurrent attempt or an upstream outage says nothing
 * about the document, so writing a failed row would be a lie.
 */
class BookingTemporarilyUnavailable extends RuntimeException {}
