<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Booking\Resources;

use Emeq\HubSdk\Booking\CheckOutcome;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CheckResultResource extends JsonResource
{
    public function __construct(private readonly CheckOutcome $outcome)
    {
        parent::__construct($outcome);
    }

    /**
     * `checked` and `valid` are separate answers: a document that was never
     * checked is not the same as one that was checked and came back invalid.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $result = $this->outcome->result ?? [];

        return [
            'module' => $this->outcome->module,
            'id' => $this->outcome->id,
            'checked' => $this->outcome->checked(),
            'status' => $this->outcome->status,
            'may_retry' => $this->outcome->mayRetry(),
            'retry_after' => $this->outcome->retryAfter,
            'valid' => (bool) ($result['valid'] ?? false),
            'summary' => $result['summary'] ?? null,
            'findings' => $result['findings'] ?? [],
            'message' => $this->outcome->message,
        ];
    }
}
