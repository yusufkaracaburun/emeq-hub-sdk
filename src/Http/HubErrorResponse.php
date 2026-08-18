<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http;

use Emeq\HubSdk\Exceptions\HubException;
use Saloon\Http\Response;
use Throwable;

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

    private static function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        if (! is_string($header) || ! ctype_digit(mb_trim($header))) {
            return null;
        }

        return (int) mb_trim($header);
    }

    /** @return array<string, mixed> */
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
