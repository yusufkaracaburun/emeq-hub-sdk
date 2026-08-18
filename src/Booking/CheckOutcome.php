<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Booking;

final class CheckOutcome
{
    /**
     * @param  int  $status  200 when Hub answered; 404 / 403 / 422 / 502 / 503 otherwise
     * @param  array<string, mixed>|null  $result  Hub's validation payload, or null when the document was never checked
     * @param  string|null  $message  why it was not checked
     * @param  int|null  $retryAfter  seconds Hub asked the caller to wait, when it said so
     */
    public function __construct(
        public readonly string $module,
        public readonly string $id,
        public readonly int $status,
        public readonly ?array $result,
        public readonly ?string $message,
        public readonly ?int $retryAfter = null,
    ) {}

    public function checked(): bool
    {
        return $this->result !== null;
    }

    public function mayRetry(): bool
    {
        return $this->status === 503;
    }
}
