<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Backlog\Resources;

use Emeq\HubSdk\Booking\HubDocument;
use Emeq\HubSdk\Booking\Resources\BookingResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use stdClass;

class BacklogDocumentResource extends JsonResource
{
    public function __construct(private readonly stdClass $document)
    {
        parent::__construct($document);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $booking = $this->document->hub_document ?? null;
        $accountingChangedAt = $this->document->accounting_changed_at ?? null;

        return [
            'module' => $this->document->module,
            'uuid' => $this->document->uuid,
            'number' => $this->document->number,
            'date' => $this->document->date,
            'amount' => $this->document->amount,
            'party' => $this->document->party,
            'direction' => $this->document->direction,
            'head' => $this->document->head,
            'document_status' => $this->document->document_status,
            'status' => $this->document->status,
            'booking' => BookingResource::maybe($booking instanceof HubDocument ? $booking : null),
            'accounting_changed_at' => is_string($accountingChangedAt) ? Carbon::parse($accountingChangedAt)->toIso8601String() : null,
            'accounting_change_action' => $this->document->accounting_change_action ?? null,
        ];
    }
}
