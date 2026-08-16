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

    public static function unavailable(?string $message = null): static
    {
        return new static(false, 503, $message ?? BookingMessages::line('temporarily_unavailable'), null);
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
