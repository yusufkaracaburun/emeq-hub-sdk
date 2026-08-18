<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Webhooks;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class HubWebhookHeaders
{
    public const SIGNATURE = 'Signature';

    public const TIMESTAMP = 'Timestamp';

    public const EVENT_ID = 'X-Emeq-Event-Id';

    public const REQUEST_ID = 'X-Emeq-Request-Id';

    /** @return list<string> */
    public static function storeHeaders(): array
    {
        return [
            self::EVENT_ID,
            self::REQUEST_ID,
            self::TIMESTAMP,
        ];
    }

    /** @param  array<string, mixed>  $headers */
    public static function value(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) !== 0) {
                continue;
            }

            if (is_array($value)) {
                $value = $value[0] ?? null;
            }

            return is_scalar($value) ? (string) $value : null;
        }

        return null;
    }

    /** @param  Builder<covariant Model>  $query */
    public static function whereEventId(Builder $query, string $eventId): void
    {
        $key = strtolower(self::EVENT_ID);

        $query->where(function (Builder $query) use ($key, $eventId): void {
            $query
                ->where("headers->{$key}", $eventId)
                ->orWhere("headers->{$key}[0]", $eventId)
                ->orWhereJsonContains("headers->{$key}", $eventId);
        });
    }

    /** @param  array<string, mixed>  $headers */
    public static function eventId(array $headers): ?string
    {
        $value = self::value($headers, self::EVENT_ID);

        return ($value !== null && $value !== '') ? $value : null;
    }

    /** @param  array<string, mixed>  $headers */
    public static function requestId(array $headers): ?string
    {
        $value = self::value($headers, self::REQUEST_ID);

        return ($value !== null && $value !== '') ? $value : null;
    }
}
