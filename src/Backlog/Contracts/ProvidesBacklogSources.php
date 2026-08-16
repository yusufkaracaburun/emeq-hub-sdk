<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Backlog\Contracts;

use Emeq\HubSdk\Backlog\BacklogRepository;
use Emeq\HubSdk\Backlog\PostedDocuments;
use Illuminate\Database\Query\Builder;

/**
 * Which of a consumer's documents are waiting to be booked.
 *
 * The one thing this package cannot know: every consumer keeps its documents in
 * its own tables, with its own idea of "finished enough to book". Implement this
 * once per app and {@see BacklogRepository} does the rest —
 * the join against the ledger, filtering, sorting, paging and the summary.
 *
 * Exclude documents that are already posted with
 * {@see PostedDocuments}: pruning before the union is much
 * cheaper than filtering the joined result.
 */
interface ProvidesBacklogSources
{
    /**
     * Every source query answers with exactly these columns, whatever table it
     * reads. `uuid` is the document's `external_id` — the ledger key.
     */
    public const COLUMNS = [
        'module',
        'uuid',
        'number',
        'date',
        'amount',
        'party',
        'direction',
        'head',
        'document_status',
    ];

    /**
     * One query over the requested modules — union the ones you support.
     * Unknown names are the implementation's to drop; an empty selection means
     * every module.
     *
     * @param  list<string>  $modules
     */
    public function bookable(array $modules): Builder;

    /**
     * Every module name this consumer can book. Travels in request payloads and
     * in the ledger, so renaming one breaks stored rows as well as the frontend.
     *
     * @return list<string>
     */
    public function modules(): array;
}
