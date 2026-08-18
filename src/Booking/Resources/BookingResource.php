<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Booking\Resources;

use Emeq\HubSdk\Booking\HubDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin HubDocument */
class BookingResource extends JsonResource
{
    /** @return array<string, mixed>|null */
    public static function maybe(?HubDocument $record): ?array
    {
        return $record === null ? null : static::make($record)->resolve();
    }

    /** @return array<string, mixed> */
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
