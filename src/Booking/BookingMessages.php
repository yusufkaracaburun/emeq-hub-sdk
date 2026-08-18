<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Booking;

use Illuminate\Support\Facades\Lang;

final class BookingMessages
{
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
