<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Booking\Resources;

use Emeq\HubSdk\Booking\HubDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One ledger row, as a frontend needs it.
 *
 * Carries `attempted_at` alongside `booked_at` because the two answer different
 * questions: when the bookkeeping accepted it, and when this consumer last
 * tried. A refused document has the second and not the first.
 *
 * `request_id` is here so it reaches a screen: a support question that quotes it
 * turns the Hub side of the story into one lookup. Storing it and not exposing
 * it would move the search rather than end it. Null on a ledger that has not
 * published the trace migration, and on a row decided before it did.
 *
 * `category` travels with it so a frontend can branch — "the connection is
 * broken" versus "this document is wrong" — without knowing every error code.
 *
 * `warnings` reports what Hub did to the relation while booking — a checkbox
 * used to ask permission for this, and now the report has to arrive instead.
 * Empty on a row this consumer only just read back, not just-booked.
 *
 * @mixin HubDocument
 */
class BookingResource extends JsonResource
{
    /**
     * Most call sites hold a nullable row — a document that was never
     * attempted has none, and that is not an error.
     *
     * @return array<string, mixed>|null
     */
    public static function maybe(?HubDocument $record): ?array
    {
        return $record === null ? null : static::make($record)->resolve();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status,
            'external_ref' => $this->external_ref,
            'external_number' => $this->external_number,
            'error' => $this->error,
            'error_message' => $this->error_message,
            'category' => $this->category,
            'request_id' => $this->request_id,
            'booked_at' => $this->booked_at?->toIso8601String(),
            'attempted_at' => $this->updated_at?->toIso8601String(),
            'accounting_changed_at' => $this->accounting_changed_at?->toIso8601String(),
            'accounting_change_action' => $this->accounting_change_action,
            'warnings' => $this->warnings,
        ];
    }
}
