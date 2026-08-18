<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Backlog;

use Emeq\HubSdk\Backlog\Contracts\ProvidesBacklogSources;
use Emeq\HubSdk\Booking\AccountingChangeRecorder;
use Emeq\HubSdk\Booking\BookingLedger;
use Emeq\HubSdk\Booking\HubDocument;
use Emeq\HubSdk\Contracts\ResolvesAccountId;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use stdClass;

class BacklogRepository
{
    public const DIRECTIONS = ['sales', 'purchase'];

    public const SORTS = ['date', 'number', 'amount', 'party'];

    public const MAX_PAGE_LENGTH = 200;

    private const LIKE_ESCAPE = '!';

    public function __construct(
        protected readonly ProvidesBacklogSources $sources,
        protected readonly BookingLedger $ledger,
        protected readonly ResolvesAccountId $account,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function paginate(array $params): LengthAwarePaginator
    {
        $page = $this->sorted($params)->paginate($this->pageLength($params));

        $this->attachBookings($page->items());

        return $page;
    }

    /** @param  array<string, mixed>  $params */
    public function summary(array $params): BacklogSummary
    {
        $groups = $this->connection()->query()
            ->fromSub($this->filtered($params), 'backlog')
            ->selectRaw('module, status, COUNT(*) as documents, SUM(amount) as amount, MIN(date) as oldest_date')
            ->groupBy('module', 'status')
            ->get();

        $byStatus = array_fill_keys(BacklogStatus::all(), 0);
        $byModule = array_fill_keys($this->sources->modules(), 0);
        $amountTotal = 0.0;
        $oldestDate = null;

        foreach ($groups as $group) {
            $documents = (int) $group->documents;
            $status = (string) $group->status;
            $module = (string) $group->module;

            $amountTotal += (float) $group->amount;
            $byStatus[$status] = ($byStatus[$status] ?? 0) + $documents;
            $byModule[$module] = ($byModule[$module] ?? 0) + $documents;

            $oldest = $group->oldest_date === null ? null : (string) $group->oldest_date;

            if ($oldest !== null && ($oldestDate === null || $oldest < $oldestDate)) {
                $oldestDate = $oldest;
            }
        }

        $accountingChanged = AccountingChangeRecorder::marksChanges()
            ? (int) $this->connection()->query()
                ->fromSub($this->filtered($params), 'backlog')
                ->whereNotNull('accounting_changed_at')
                ->count()
            : 0;

        return new BacklogSummary(array_sum($byStatus), $amountTotal, $byStatus, $byModule, $oldestDate, $accountingChanged);
    }

    /** @param  array<int, mixed>  $items */
    protected function attachBookings(array $items): void
    {
        $externalIds = [];

        foreach ($items as $item) {
            if ($item instanceof stdClass && is_string($item->uuid ?? null)) {
                $externalIds[] = $item->uuid;
            }
        }

        $bookings = $this->ledger->forExternalIds($externalIds);

        foreach ($items as $item) {
            if ($item instanceof stdClass && is_string($item->uuid ?? null)) {
                $item->hub_document = $bookings->get($item->uuid);
            }
        }
    }

    /** @param  array<string, mixed>  $params */
    protected function sorted(array $params): Builder
    {
        $sortBy = in_array($params['sort_by'] ?? null, self::SORTS, true) ? (string) $params['sort_by'] : 'date';
        $order = ($params['order'] ?? null) === 'asc' ? 'asc' : 'desc';

        $documents = $this->filtered($params)->orderBy($sortBy, $order);

        if ($sortBy !== 'number') {
            $documents->orderBy('number', 'desc');
        }

        return $documents->orderBy('uuid');
    }

    /** @param  array<string, mixed>  $params */
    protected function filtered(array $params): Builder
    {
        $modules = array_values(array_filter(
            (array) ($params['modules'] ?? []),
            static fn (mixed $module): bool => is_string($module),
        ));

        $tracksChanges = AccountingChangeRecorder::marksChanges();

        $select = [
            ...array_map(static fn (string $column): string => 'documents.'.$column, ProvidesBacklogSources::COLUMNS),
            DB::raw("COALESCE(bookings.status, '".BacklogStatus::NOT_BOOKED."') as status"),
        ];

        if ($tracksChanges) {
            $select[] = 'bookings.accounting_changed_at';
            $select[] = 'bookings.accounting_change_action';
        }

        $documents = $this->connection()->query()
            ->fromSub($this->sources->bookable($modules), 'documents')
            ->leftJoinSub($this->latestBookings(), 'bookings', 'bookings.external_id', '=', 'documents.uuid')
            ->select($select);

        if (is_string($params['search_term'] ?? null) && $params['search_term'] !== '') {
            $term = '%'.self::escapeWildcards($params['search_term']).'%';
            $documents->where(function (Builder $query) use ($term): void {
                $query
                    ->whereRaw("number like ? escape '".self::LIKE_ESCAPE."'", [$term])
                    ->orWhereRaw("party like ? escape '".self::LIKE_ESCAPE."'", [$term]);
            });
        }

        $startDate = $this->date($params, 'start_date');
        $endDate = $this->date($params, 'end_date');

        if ($startDate !== null) {
            $documents->whereDate('date', '>=', $startDate);
        }

        if ($endDate !== null) {
            $documents->whereDate('date', '<=', $endDate);
        }

        if (in_array($params['direction'] ?? null, self::DIRECTIONS, true)) {
            $documents->where('documents.direction', $params['direction']);
        }

        $minAmount = $this->amount($params, 'min_amount');
        $maxAmount = $this->amount($params, 'max_amount');

        if ($minAmount !== null) {
            $documents->where('documents.amount', '>=', $minAmount);
        }

        if ($maxAmount !== null) {
            $documents->where('documents.amount', '<=', $maxAmount);
        }

        if ($this->wantsAccountingChanged($params)) {
            if ($tracksChanges) {
                $documents->whereNotNull('bookings.accounting_changed_at');
            } else {
                $documents->whereRaw('1 = 0');
            }
        }

        return $this->filterByStatus($documents, $this->requestedStatuses($params));
    }

    private static function escapeWildcards(string $term): string
    {
        $escape = self::LIKE_ESCAPE;

        return str_replace([$escape, '%', '_'], [$escape.$escape, $escape.'%', $escape.'_'], $term);
    }

    /** @param  array<string, mixed>  $params */
    protected function wantsAccountingChanged(array $params): bool
    {
        return filter_var($params['accounting_changed'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /** @param  list<string>  $statuses */
    protected function filterByStatus(Builder $documents, array $statuses): Builder
    {
        if ($statuses === []) {
            return $documents;
        }

        $booked = array_values(array_diff($statuses, [BacklogStatus::NOT_BOOKED]));

        return $documents->where(function (Builder $query) use ($statuses, $booked): void {
            if ($booked !== []) {
                $query->whereIn('bookings.status', $booked);
            }

            if (in_array(BacklogStatus::NOT_BOOKED, $statuses, true)) {
                $query->orWhereNull('bookings.status');
            }
        });
    }

    protected function latestBookings(): Builder
    {
        $table = (new HubDocument)->getTable();

        $select = [
            $table.'.external_id',
            $table.'.status',
        ];

        if (AccountingChangeRecorder::marksChanges()) {
            $select[] = $table.'.accounting_changed_at';
            $select[] = $table.'.accounting_change_action';
        }

        return $this->connection()->table($table)
            ->joinSub(HubDocument::currentIds($this->account->accountId()), 'current', 'current.id', '=', $table.'.id')
            ->select($select);
    }

    protected function connection(): Connection
    {
        return DB::connection((new HubDocument)->getConnectionName());
    }

    /** @param  array<string, mixed>  $params */
    protected function pageLength(array $params): int
    {
        $requested = $params['page_length'] ?? Config::get('hub.booking.page_length');

        $length = is_numeric($requested) ? (int) $requested : 25;

        return max(1, min($length, self::MAX_PAGE_LENGTH));
    }

    /** @param  array<string, mixed>  $params */
    protected function date(array $params, string $key): ?string
    {
        $value = $params[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param  array<string, mixed>  $params */
    protected function amount(array $params, string $key): ?float
    {
        $value = $params[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<string>
     */
    protected function requestedStatuses(array $params): array
    {
        return array_values(array_filter(
            (array) ($params['status'] ?? []),
            static fn (mixed $status): bool => is_string($status),
        ));
    }
}
