<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Support;

use InvalidArgumentException;

/**
 * Normalizes and validates hub.routes.middleware for the opt-in BFF.
 */
final class HubRouteMiddleware
{
    /**
     * @return list<string>
     */
    public static function normalize(mixed $middleware): array
    {
        if (! is_array($middleware)) {
            $middleware = is_scalar($middleware)
                ? explode(',', (string) $middleware)
                : [];
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $m): string => is_scalar($m) ? trim((string) $m) : '',
                $middleware,
            ),
            static fn (string $m): bool => $m !== '',
        ));
    }

    /**
     * @param  list<string>  $middleware
     */
    public static function assertNotEmpty(array $middleware): void
    {
        if ($middleware === []) {
            throw new InvalidArgumentException(
                'hub.routes.middleware must not be empty when hub.routes.enabled is true. '
                .'Refusing to register unauthenticated Hub BFF routes.',
            );
        }
    }
}
