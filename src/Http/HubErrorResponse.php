<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http;

use Emeq\HubSdk\Exceptions\HubException;
use Saloon\Http\Response;
use Throwable;

/**
 * Decodes a failed Hub response into a typed HubException.
 *
 * Single owner of "what a Hub error body looks like on the wire" — both the
 * response middleware and the connector's exception hook delegate here.
 */
final class HubErrorResponse
{
    public static function toException(Response $response, ?Throwable $previous = null): HubException
    {
        return HubException::fromEnvelope(self::body($response), $response->status(), $previous);
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(Response $response): array
    {
        try {
            $decoded = $response->json();
        } catch (Throwable) {
            return ['message' => $response->body()];
        }

        if (! is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
