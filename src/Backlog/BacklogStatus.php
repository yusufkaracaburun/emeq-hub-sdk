<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Backlog;

use Emeq\HubSdk\Booking\HubDocument;

/**
 * The states a document can be in while it is still in the backlog.
 *
 * `posted` is deliberately absent: a posted document has left the backlog.
 */
final class BacklogStatus
{
    /**
     * No decided attempt exists. Distinct from `failed`: nobody has tried.
     */
    public const NOT_BOOKED = 'not_booked';

    /**
     * @return list<string>
     */
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
