<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Exceptions;

use Exception;
use Throwable;

class HubException extends Exception
{
    /**
     * `$retryAfter` and everything behind it sit past `$previous` rather than in
     * their conventional place: appending keeps every existing positional
     * construction valid, and consumers catch these far more often than they
     * build them.
     *
     * `$retryable` is null when Hub did not say — an older Hub, or a body that
     * never reached the envelope middleware. Null means "no opinion", not
     * "no": a caller that treats it as false loses the distinction the field
     * exists for.
     *
     * @param  array<string, list<string>>  $validationErrors  Hub's per-field messages on a 422
     */
    public function __construct(
        string $message,
        public readonly string $error = 'hub_error',
        public readonly string $category = 'UNKNOWN_ERROR',
        public readonly ?string $requestId = null,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
        public readonly ?int $retryAfter = null,
        public readonly ?bool $retryable = null,
        public readonly array $validationErrors = [],
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Laravel's exception handler calls this and merges the result into the log
     * record, so every consumer gets the value that ties this failure to a Hub
     * log line without configuring anything.
     *
     * Prefixed, because this lands in a log context the consumer also writes to.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return array_filter([
            'hub_request_id' => $this->requestId,
            'hub_error' => $this->error,
            'hub_category' => $this->category,
            'hub_status' => $this->status,
        ], static fn (mixed $value): bool => $value !== null);
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
     * Only a real boolean counts. A missing key and a `"true"` that some proxy
     * stringified both mean "Hub did not tell us", and guessing either way loses
     * the difference between "do not retry" and "no opinion".
     */
    private static function flag(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /**
     * Laravel's per-field validation messages, which the envelope leaves in place
     * on a 422. Worth surfacing: a rejected document says which field it was, and
     * finding that out from a single sentence means guessing.
     *
     * @return array<string, list<string>>
     */
    private static function messagesByField(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $errors = [];

        foreach ($value as $field => $messages) {
            $lines = array_values(array_filter(
                array_map(self::text(...), is_array($messages) ? $messages : [$messages]),
                static fn (?string $line): bool => $line !== null,
            ));

            if ($lines !== []) {
                $errors[(string) $field] = $lines;
            }
        }

        return $errors;
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
        $retryable = self::flag($body['retryable'] ?? null);
        $errors = self::messagesByField($body['errors'] ?? null);

        return match (true) {
            $status === 401, $category === 'AUTHENTICATION_ERROR' => new AuthenticationException($message, $error, $category, $requestId, $status, $previous, $retryAfter, $retryable, $errors),
            $status === 403, $category === 'AUTHORIZATION_ERROR' => new AuthorizationException($message, $error, $category, $requestId, $status, $previous, $retryAfter, $retryable, $errors),
            $status === 404 => new NotFoundException($message, $error, $category, $requestId, $status, $previous, $retryAfter, $retryable, $errors),
            $status === 422, $category === 'VALIDATION_ERROR' => new ValidationException($message, $error, $category, $requestId, $status, $previous, $retryAfter, $retryable, $errors),
            $status === 429 => new RateLimitException($message, $error, $category, $requestId, $status, $previous, $retryAfter, $retryable, $errors),
            $status >= 500 => new ServerException($message, $error, $category, $requestId, $status, $previous, $retryAfter, $retryable, $errors),
            default => new self($message, $error, $category, $requestId, $status, $previous, $retryAfter, $retryable, $errors),
        };
    }
}
