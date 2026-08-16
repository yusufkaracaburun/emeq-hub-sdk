<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Exceptions;

use Exception;
use Throwable;

class HubException extends Exception
{
    /**
     * `$retryAfter` sits behind `$previous` rather than in its conventional last
     * place: appending keeps every existing positional construction valid, and
     * consumers catch these far more often than they build them.
     */
    public function __construct(
        string $message,
        public readonly string $error = 'hub_error',
        public readonly string $category = 'UNKNOWN_ERROR',
        public readonly ?string $requestId = null,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Hub's body is untrusted JSON: anything non-scalar is treated as absent
     * rather than cast, which would yield "Array" or an emitted warning.
     */
    private static function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromEnvelope(array $body, int $status, ?Throwable $previous = null, ?int $retryAfter = null): self
    {
        $error = self::text($body['error'] ?? $body['code'] ?? null) ?? 'hub_error';
        $category = self::text($body['category'] ?? null) ?? 'UNKNOWN_ERROR';
        $message = self::text($body['message'] ?? null) ?? $error;
        $requestId = self::text($body['request_id'] ?? null);

        return match (true) {
            $status === 401, $category === 'AUTHENTICATION_ERROR' => new AuthenticationException($message, $error, $category, $requestId, $status, $previous, $retryAfter),
            $status === 403, $category === 'AUTHORIZATION_ERROR' => new AuthorizationException($message, $error, $category, $requestId, $status, $previous, $retryAfter),
            $status === 404 => new NotFoundException($message, $error, $category, $requestId, $status, $previous, $retryAfter),
            $status === 422, $category === 'VALIDATION_ERROR' => new ValidationException($message, $error, $category, $requestId, $status, $previous, $retryAfter),
            $status === 429 => new RateLimitException($message, $error, $category, $requestId, $status, $previous, $retryAfter),
            $status >= 500 => new ServerException($message, $error, $category, $requestId, $status, $previous, $retryAfter),
            default => new self($message, $error, $category, $requestId, $status, $previous, $retryAfter),
        };
    }
}
