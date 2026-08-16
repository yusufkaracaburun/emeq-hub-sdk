<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Exceptions;

/**
 * This exact document is already being booked — by a concurrent attempt here,
 * or by one Hub is still working on.
 *
 * A narrower {@see BookingTemporarilyUnavailable}: same retry policy, but the
 * user is told to wait for the run they already started instead of being sent
 * looking at the bookkeeping.
 */
class BookingAlreadyInProgress extends BookingTemporarilyUnavailable {}
