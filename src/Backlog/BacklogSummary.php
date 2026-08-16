<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Backlog;

/**
 * What the current backlog filter has in front of the bookkeeper.
 *
 * Counted over the whole filtered set, not over the current page — the point of
 * a summary is what paging hides.
 */
final class BacklogSummary
{
    /**
     * @param  array<string, int>  $byStatus  every {@see BacklogStatus}, zero-filled
     * @param  array<string, int>  $byModule  every module the sources declare, zero-filled
     * @param  string|null  $oldestDate  a closed fiscal year upstream refuses every posting,
     *                                   and no validation call sees that in advance
     */
    public function __construct(
        public readonly int $total,
        public readonly float $amountTotal,
        public readonly array $byStatus,
        public readonly array $byModule,
        public readonly ?string $oldestDate,
    ) {}
}
