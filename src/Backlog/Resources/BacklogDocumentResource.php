<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Backlog\Resources;

use Emeq\HubSdk\Backlog\BacklogRepository;
use Emeq\HubSdk\Booking\HubDocument;
use Emeq\HubSdk\Booking\Resources\BookingResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use stdClass;

/**
 * One row from {@see BacklogRepository::paginate()}.
 *
 * `status` is the joined backlog state — always present, `not_booked` when no
 * attempt was decided. `booking` is the full ledger row and is null exactly
 * then. A frontend needs both: the first sorts and filters, the second explains.
 */
class BacklogDocumentResource extends JsonResource
{
    public function __construct(private readonly stdClass $document)
    {
        parent::__construct($document);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $booking = $this->document->hub_document ?? null;

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
        ];
    }
}
