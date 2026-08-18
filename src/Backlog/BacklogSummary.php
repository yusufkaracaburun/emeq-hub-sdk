<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Backlog;

final class BacklogSummary
{
    /**
     * @param  array<string, int>  $byStatus  every {@see BacklogStatus}, zero-filled
     * @param  array<string, int>  $byModule  every module the sources declare, zero-filled
     * @param  string|null  $oldestDate  a closed fiscal year upstream refuses every posting,
     *                                   and no validation call sees that in advance
     * @param  int  $accountingChanged  documents the bookkeeping changed after this consumer
     *                                  booked them — orthogonal to `$byStatus`, since such a
     *                                  document is `posted` *and* changed, not a status of its own
     */
    public function __construct(
        public readonly int $total,
        public readonly float $amountTotal,
        public readonly array $byStatus,
        public readonly array $byModule,
        public readonly ?string $oldestDate,
        public readonly int $accountingChanged = 0,
    ) {}
}
