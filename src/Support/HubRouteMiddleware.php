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
                'hub.routes.middleware must not be empty when hub.routes.enabled is true.',
            );
        }
    }

    /**
     * The BFF mints Hub connect-session URLs for whatever ResolvesAccountId
     * returns, so an unauthenticated POST hands a partner OAuth handoff to
     * anyone. Middleware named outside the `auth` family (`tenant.auth`,
     * a Sanctum wrapper) is invisible here — set
     * `hub.routes.allow_unauthenticated` to declare it deliberate.
     *
     * @param  list<string>  $middleware
     */
    public static function assertAuthenticated(array $middleware, bool $allowUnauthenticated = false): void
    {
        if ($allowUnauthenticated) {
            return;
        }

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
        $name = strtolower($middleware);

        return $name === 'auth'
            || str_starts_with($name, 'auth:')
            || str_starts_with($name, 'auth.');
    }
}
