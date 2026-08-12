<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Webhooks;

/**
 * Correlation / dedupe headers Hub sets on outbound consumer webhooks.
 */
final class HubWebhookHeaders
{
    public const SIGNATURE = 'Signature';

    public const TIMESTAMP = 'Timestamp';

    public const EVENT_ID = 'X-Emeq-Event-Id';

    public const REQUEST_ID = 'X-Emeq-Request-Id';

    /**
     * Headers Spatie webhook-client should persist for processing.
     *
     * @return list<string>
     */
    public static function storeHeaders(): array
    {
        return [
            self::EVENT_ID,
            self::REQUEST_ID,
            self::TIMESTAMP,
        ];
    }

    /**
     * Case-insensitive header lookup from a Spatie-stored headers array.
     *
     * @param  array<string, mixed>  $headers
     */
    public static function value(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) !== 0) {
                continue;
            }

            if (is_array($value)) {
                return isset($value[0]) ? (string) $value[0] : null;
            }

            return (string) $value;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    public static function eventId(array $headers): ?string
    {
        $value = self::value($headers, self::EVENT_ID);

        return ($value !== null && $value !== '') ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    public static function requestId(array $headers): ?string
    {
        $value = self::value($headers, self::REQUEST_ID);

        return ($value !== null && $value !== '') ? $value : null;
    }
}
