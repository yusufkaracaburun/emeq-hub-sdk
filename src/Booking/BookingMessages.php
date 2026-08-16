<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Booking;

use Illuminate\Support\Facades\Lang;

/**
 * The copy this package decides on a caller's behalf.
 *
 * One place, so a consumer that publishes `hub-translations` rewords every
 * outcome at once instead of hunting for the ones it missed.
 */
final class BookingMessages
{
    /**
     * Copy for one of Hub's error codes, falling back to the generic line for
     * a code this release does not know — Hub can add one without an SDK
     * release, and an unlabelled failure is worse than a vague one.
     */
    public static function forError(?string $error): string
    {
        return Lang::has('hub::booking.error.'.$error)
            ? self::line('error.'.$error)
            : self::line('error.unknown');
    }

    public static function line(string $key): string
    {
        $line = trans('hub::booking.'.$key);

        return is_string($line) ? $line : $key;
    }
}
