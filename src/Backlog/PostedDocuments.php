<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Backlog;

use Closure;
use Emeq\HubSdk\Booking\HubDocument;
use Emeq\HubSdk\Contracts\ResolvesAccountId;
use Illuminate\Database\Query\Builder;

/**
 * The "already in the bookkeeping" filter, for consumer backlog sources.
 *
 * Ships here rather than in each consumer because it is ledger SQL, and because
 * getting the account scope wrong is silent: an unscoped exclusion hides another
 * administration's postings from this one's backlog.
 */
final class PostedDocuments
{
    public function __construct(private readonly ResolvesAccountId $account) {}

    /**
     * ```php
     * $query->whereNotExists($this->posted->excluding('invoices.uuid'));
     * ```
     *
     * A posted document the bookkeeping later changed is not excluded: it
     * needs attention again, so it belongs back in the backlog.
     * {@see BacklogRepository} decides from there whether to show it, through
     * the `accounting_changed` filter.
     *
     * @param  string  $uuidColumn  qualified column holding the document's external_id
     * @return Closure(Builder): void
     */
    public function excluding(string $uuidColumn): Closure
    {
        $accountId = $this->account->accountId();
        $table = (new HubDocument)->getTable();

        return function (Builder $query) use ($uuidColumn, $accountId, $table): void {
            $query->selectRaw('1')
                ->from($table)
                ->whereColumn($table.'.external_id', $uuidColumn)
                ->where($table.'.account_id', $accountId)
                ->where($table.'.status', HubDocument::STATUS_POSTED)
                ->whereNull($table.'.accounting_changed_at');
        };
    }
}
