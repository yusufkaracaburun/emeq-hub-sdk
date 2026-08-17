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
     * Copy for a row that carries an error code but no message.
     *
     * Only rows this package did not write get here: everything it stores keeps
     * Hub's own message, which names the relation or ledger account a generic
     * line cannot. Hub is therefore the single source for what a failure says,
     * and a code it adds needs no release here.
     *
     * A consumer that publishes `hub-translations` and adds `error.<code>` takes
     * that decision back for the codes it names — the key wins where it exists.
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
