<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Booking;

/**
 * What a dry run said about one document.
 *
 * Checking costs nothing upstream and catches most refusals before a batch
 * writes anything, so `result === null` (never checked) is a different answer
 * from a check that came back with findings.
 *
 * Carries the same status vocabulary as {@see BookingOutcome}, and for the same
 * reason: "this document is wrong" (422) and "Hub could not answer" (502) look
 * alike to a caller that only has a message, and only one of them is the user's
 * to fix.
 */
final class CheckOutcome
{
    /**
     * @param  int  $status  200 when Hub answered; 404 / 403 / 422 / 502 / 503 otherwise
     * @param  array<string, mixed>|null  $result  Hub's validation payload, or null when the document was never checked
     * @param  string|null  $message  why it was not checked
     */
    public function __construct(
        public readonly string $module,
        public readonly string $id,
        public readonly int $status,
        public readonly ?array $result,
        public readonly ?string $message,
    ) {}

    public function checked(): bool
    {
        return $this->result !== null;
    }

    /**
     * The check failed for a reason unrelated to the document. Only this one
     * may be repeated as-is.
     */
    public function mayRetry(): bool
    {
        return $this->status === 503;
    }
}
