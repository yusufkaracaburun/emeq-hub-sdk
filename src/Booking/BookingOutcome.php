<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Booking;

/**
 * What a caller should tell the user, and with which HTTP status.
 *
 * Separates the three ways a booking can not happen: refused (the document is
 * wrong — 422), unavailable (nothing was decided, retry — 503) and upstream
 * failure (Hub answered an error — 502). Only `unavailable` may be retried
 * blindly; see {@see self::mayRetry()}.
 */
class BookingOutcome
{
    final private function __construct(
        public readonly bool $booked,
        public readonly int $status,
        public readonly ?string $message,
        public readonly ?HubDocument $record,
        public readonly bool $needsManualCheck = false,
        public readonly ?string $reason = null,
        public readonly ?int $retryAfter = null,
    ) {}

    /**
     * The outcome a decided ledger row describes.
     *
     * Prefers Hub's own message over the translated copy: it names the ledger
     * account or relation the bookkeeping is missing, which the generic phrase
     * cannot.
     */
    public static function from(HubDocument $record): static
    {
        if ($record->status === HubDocument::STATUS_UNKNOWN) {
            return static::unknown($record, $record->error_message ?: BookingMessages::forError($record->error));
        }

        if ($record->status !== HubDocument::STATUS_POSTED) {
            return static::refused($record->error_message ?: BookingMessages::forError($record->error), $record);
        }

        return static::booked($record);
    }

    public static function booked(HubDocument $record): static
    {
        return new static(true, 200, null, $record);
    }

    public static function refused(string $message, ?HubDocument $record = null): static
    {
        return new static(false, 422, $message, $record);
    }

    /**
     * The send was interrupted: the document may or may not be in the
     * bookkeeping. Reported as a refusal so nothing resends it automatically,
     * flagged so a human goes and looks.
     */
    public static function unknown(HubDocument $record, string $message): static
    {
        return new static(false, 422, $message, $record, needsManualCheck: true);
    }

    /**
     * The bookkeeping said nothing usable. `$reason` carries the exception text
     * for the consumer's log; the screen gets the translated line, because the
     * exception names an external id and speaks English.
     *
     * `$retryAfter` is how long Hub asked the caller to wait, when it said so.
     * A queued retry should honour it; see {@see self::mayRetry()}.
     */
    public static function unavailable(?string $reason = null, ?int $retryAfter = null): static
    {
        return new static(false, 503, BookingMessages::line('temporarily_unavailable'), null, reason: $reason, retryAfter: $retryAfter);
    }

    /**
     * The same document is already on its way — wait for that run rather than
     * go and look at the bookkeeping. Retryable like any other 503, but the
     * user is told which of the two waits they are in.
     */
    public static function alreadyInProgress(?string $reason = null, ?int $retryAfter = null): static
    {
        return new static(false, 503, BookingMessages::line('already_in_progress'), null, reason: $reason, retryAfter: $retryAfter);
    }

    public static function upstreamFailure(string $message): static
    {
        return new static(false, 502, $message, null);
    }

    public static function notFound(?string $message = null): static
    {
        return new static(false, 404, $message ?? BookingMessages::line('not_found'), null);
    }

    public static function notAllowed(?string $message = null): static
    {
        return new static(false, 403, $message ?? BookingMessages::line('not_allowed'), null);
    }

    public function mayRetry(): bool
    {
        return $this->status === 503;
    }
}
