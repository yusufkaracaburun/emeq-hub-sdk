<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Resources;

/**
 * Reads the `blocking` field off one `validateDocument()` finding.
 *
 * Hub only started sending `blocking` in August 2026; older or not-yet-updated
 * Hub deployments answer findings with `severity` alone, even though some
 * warnings — Hub says so in their own `message` — reject the booking anyway.
 * `blocking` is the field meant to replace that free-text guess, so this never
 * derives it from `severity` or `message`, and never turns an absent or
 * malformed field into `false`: that is the exact silent trap the field exists
 * to close. Absent or non-boolean reads as `null` — "unknown", not
 * "not blocking".
 */
final class Finding
{
    /**
     * @param  array<string, mixed>  $finding
     */
    public static function isBlocking(array $finding): ?bool
    {
        $blocking = $finding['blocking'] ?? null;

        return is_bool($blocking) ? $blocking : null;
    }
}
