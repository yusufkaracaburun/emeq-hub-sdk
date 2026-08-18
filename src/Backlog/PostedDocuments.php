<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Backlog;

use Closure;
use Emeq\HubSdk\Booking\AccountingChangeRecorder;
use Emeq\HubSdk\Booking\HubDocument;
use Emeq\HubSdk\Contracts\ResolvesAccountId;
use Emeq\HubSdk\Webhooks\HubWebhookAction;
use Illuminate\Database\Query\Builder;

final class PostedDocuments
{
    public function __construct(private readonly ResolvesAccountId $account) {}

    /**
     * @param  string  $uuidColumn  qualified column holding the document's external_id
     * @return Closure(Builder): void
     */
    public function excluding(string $uuidColumn): Closure
    {
        $accountId = $this->account->accountId();
        $table = (new HubDocument)->getTable();
        $tracksChanges = AccountingChangeRecorder::marksChanges();

        return function (Builder $query) use ($uuidColumn, $accountId, $table, $tracksChanges): void {
            $query->selectRaw('1')
                ->from($table)
                ->whereColumn($table.'.external_id', $uuidColumn)
                ->where($table.'.account_id', $accountId)
                ->where($table.'.status', HubDocument::STATUS_POSTED);

            if (! $tracksChanges) {
                return;
            }

            $query->where(function (Builder $unlessDeleted) use ($table): void {
                $unlessDeleted->whereNull($table.'.accounting_change_action')
                    ->orWhere($table.'.accounting_change_action', '!=', HubWebhookAction::DELETED->value);
            });
        };
    }
}
