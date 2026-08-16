<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Booking;

/**
 * What a dry run said about one document.
 *
 * Checking costs nothing upstream and catches most refusals before a batch
 * writes anything, so `result === null` (never checked) is a different answer
 * from a check that came back with findings.
 */
final class CheckOutcome
{
    /**
     * @param  array<string, mixed>|null  $result  Hub's validation payload, or null when the document was never checked
     * @param  string|null  $message  why it was not checked
     * @param  bool  $retryable  the check failed for a reason unrelated to the document
     */
    public function __construct(
        public readonly string $module,
        public readonly string $id,
        public readonly ?array $result,
        public readonly ?string $message,
        public readonly bool $retryable = false,
    ) {}

    public function checked(): bool
    {
        return $this->result !== null;
    }
}
