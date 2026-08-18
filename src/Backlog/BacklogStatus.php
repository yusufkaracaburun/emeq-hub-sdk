<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Backlog;

use Emeq\HubSdk\Booking\HubDocument;

final class BacklogStatus
{
    public const NOT_BOOKED = 'not_booked';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::NOT_BOOKED,
            HubDocument::STATUS_FAILED,
            HubDocument::STATUS_REJECTED,
            HubDocument::STATUS_UNKNOWN,
        ];
    }
}
