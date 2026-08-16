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
        return HubException::fromEnvelope(
            self::body($response),
            $response->status(),
            $previous,
            self::retryAfter($response),
        );
    }

    /**
     * How long Hub asked the caller to wait — set on the 429 its rate limiter
     * returns and on the 409 that means "this document is already on its way".
     *
     * Only the delay-in-seconds form is read. The HTTP-date the RFC also allows
     * would have to be trusted against this machine's clock, and a wrong wait is
     * worse than none: it either hammers Hub or strands the document.
     */
    private static function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        if (! is_string($header) || ! ctype_digit(mb_trim($header))) {
            return null;
        }

        return (int) mb_trim($header);
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
