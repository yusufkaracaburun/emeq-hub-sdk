<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Support;

use InvalidArgumentException;

final class HubRouteMiddleware
{
    /** @return list<string> */
    public static function validated(): array
    {
        $middleware = self::normalize(config('hub.routes.middleware'));

        self::assertNotEmpty($middleware);

        if (! (bool) config('hub.routes.allow_unauthenticated', false)) {
            self::assertAuthenticated($middleware);
        }

        return $middleware;
    }

    /** @return list<string> */
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

    /** @param  list<string>  $middleware */
    public static function assertNotEmpty(array $middleware): void
    {
        if ($middleware === []) {
            throw new InvalidArgumentException(
                'hub.routes.middleware must not be empty when hub.routes.enabled is true.',
            );
        }
    }

    /** @param  list<string>  $middleware */
    public static function assertAuthenticated(array $middleware): void
    {
        foreach ($middleware as $entry) {
            if (self::looksLikeAuth($entry)) {
                return;
            }
        }

        throw new InvalidArgumentException(
            'hub.routes.middleware carries no auth middleware ("auth", "auth:sanctum", …). '
            .'Refusing to register unauthenticated Hub BFF routes. '
            .'Set hub.routes.allow_unauthenticated to true if your auth middleware is named differently.',
        );
    }

    private static function looksLikeAuth(string $middleware): bool
    {
        return $middleware === 'auth'
            || $middleware === 'auth.basic'
            || str_starts_with($middleware, 'auth:');
    }
}
