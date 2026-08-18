<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Resources;

final class Finding
{
    /** @param  array<string, mixed>  $finding */
    public static function isBlocking(array $finding): ?bool
    {
        $blocking = $finding['blocking'] ?? null;

        return is_bool($blocking) ? $blocking : null;
    }
}
