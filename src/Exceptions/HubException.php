<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Exceptions;

use Exception;
use Throwable;

class HubException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $error = 'hub_error',
        public readonly string $category = 'UNKNOWN_ERROR',
        public readonly ?string $requestId = null,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromEnvelope(array $body, int $status, ?Throwable $previous = null): self
    {
        $error = (string) ($body['error'] ?? $body['code'] ?? 'hub_error');
        $category = (string) ($body['category'] ?? 'UNKNOWN_ERROR');
        $message = (string) ($body['message'] ?? $error);
        $requestId = isset($body['request_id']) ? (string) $body['request_id'] : null;

        return match (true) {
            $status === 401, $category === 'AUTHENTICATION_ERROR' => new AuthenticationException($message, $error, $category, $requestId, $status, $previous),
            $status === 403, $category === 'AUTHORIZATION_ERROR' => new AuthorizationException($message, $error, $category, $requestId, $status, $previous),
            $status === 404 => new NotFoundException($message, $error, $category, $requestId, $status, $previous),
            $status === 422, $category === 'VALIDATION_ERROR' => new ValidationException($message, $error, $category, $requestId, $status, $previous),
            $status === 429 => new RateLimitException($message, $error, $category, $requestId, $status, $previous),
            $status >= 500 => new ServerException($message, $error, $category, $requestId, $status, $previous),
            default => new self($message, $error, $category, $requestId, $status, $previous),
        };
    }
}
