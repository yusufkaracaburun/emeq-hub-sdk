<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Backlog\Resources;

use Emeq\HubSdk\Backlog\BacklogSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BacklogSummaryResource extends JsonResource
{
    public function __construct(private readonly BacklogSummary $summary)
    {
        parent::__construct($summary);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'total' => $this->summary->total,
            'amount_total' => $this->summary->amountTotal,
            'by_status' => $this->summary->byStatus,
            'by_module' => $this->summary->byModule,
            'oldest_date' => $this->summary->oldestDate,
        ];
    }
}
