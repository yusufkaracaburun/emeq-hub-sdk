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
            'booked_at' => $this->booked_at?->toIso8601String(),
            'attempted_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
