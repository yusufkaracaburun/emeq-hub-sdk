<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Booking;

use Emeq\HubSdk\Webhooks\HubWebhookAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @property int $id
 * @property string $account_id
 * @property string $type
 * @property string $external_id
 * @property string|null $party_external_id
 * @property string $status
 * @property string|null $external_ref
 * @property string|null $external_number
 * @property string|null $error
 * @property string|null $error_message
 * @property string|null $request_id
 * @property string|null $category
 * @property Carbon|null $booked_at
 * @property Carbon|null $accounting_changed_at
 * @property string|null $accounting_change_action
 * @property string|null $accounting_change_event_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class HubDocument extends Model
{
    /** @var list<string> */
    public const TRACE_COLUMNS = ['request_id', 'category'];

    /** @var list<string> */
    public const CHANGE_COLUMNS = [
        'accounting_changed_at',
        'accounting_change_action',
        'accounting_change_event_id',
    ];

    public const STATUS_POSTED = 'posted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_UNAVAILABLE = 'unavailable';

    private const CURRENT_ID = 'COALESCE(MAX(CASE WHEN status = ? THEN id END), MAX(id))';

    /** @var array<string, bool> */
    private static array $tracesRequests = [];

    protected $table = 'hub_documents';

    /** @var list<string> */
    protected $fillable = [
        'account_id',
        'type',
        'external_id',
        'party_external_id',
        'status',
        'external_ref',
        'external_number',
        'error',
        'error_message',
        'request_id',
        'category',
        'booked_at',
        'accounting_changed_at',
        'accounting_change_action',
        'accounting_change_event_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'external_number' => 'string',
        'booked_at' => 'datetime',
        'accounting_changed_at' => 'datetime',
    ];

    /** @var list<array<string, mixed>> */
    public array $warnings = [];

    public function getConnectionName(): ?string
    {
        $connection = config('hub.booking.connection');

        return is_string($connection) && $connection !== ''
            ? $connection
            : parent::getConnectionName();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function withoutMissingTrace(array $attributes): array
    {
        if (self::tracesRequests()) {
            return $attributes;
        }

        return array_diff_key($attributes, array_flip(self::TRACE_COLUMNS));
    }

    public static function tracesRequests(): bool
    {
        $model = new self;

        return self::$tracesRequests[self::schemaKey()] ??= Schema::connection($model->getConnectionName())
            ->hasColumns($model->getTable(), self::TRACE_COLUMNS);
    }

    public static function forgetTraceSupport(): void
    {
        self::$tracesRequests = [];
    }

    /**
     * Gone from the bookkeeping after we put it there. The row stays `posted`
     * because that is what happened; what the bookkeeping did with it after is
     * a separate fact, and only this one reopens the document for booking.
     */
    public function wasDeletedFromAccounting(): bool
    {
        return $this->accounting_change_action === HubWebhookAction::DELETED->value;
    }

    /**
     * What a fresh posting leaves behind: nothing. The columns are absent on a
     * ledger that never learned about them, and are then left out.
     *
     * @return array<string, null>
     */
    public static function clearedAccountingChange(): array
    {
        return AccountingChangeRecorder::marksChanges()
            ? array_fill_keys(self::CHANGE_COLUMNS, null)
            : [];
    }

    public static function schemaKey(): string
    {
        $connection = DB::connection((new self)->getConnectionName());

        return $connection->getName().'@'.$connection->getDatabaseName();
    }

    public static function currentIds(string $accountId): QueryBuilder
    {
        $model = new self;

        return DB::connection($model->getConnectionName())
            ->table($model->getTable())
            ->selectRaw(self::CURRENT_ID.' as id', [self::STATUS_POSTED])
            ->where('account_id', $accountId)
            ->groupBy('external_id');
    }

    /**
     * @param  list<string>  $externalIds
     * @return Collection<string, static>
     */
    public static function forExternalIds(array $externalIds, string $accountId): Collection
    {
        $externalIds = array_values(array_filter($externalIds));

        if ($externalIds === []) {
            return new Collection;
        }

        $documents = static::query()
            ->whereIn('id', static::currentIds($accountId)->whereIn('external_id', $externalIds))
            ->get();

        $byExternalId = [];

        foreach ($documents as $document) {
            $byExternalId[$document->external_id] = $document;
        }

        return new Collection($byExternalId);
    }

    public static function forBooking(string $externalId, string $accountId): self
    {
        $existing = static::query()
            ->whereIn('id', static::currentIds($accountId)->where('external_id', $externalId))
            ->first();

        return $existing ?? static::make([
            'account_id' => $accountId,
            'external_id' => $externalId,
        ]);
    }
}
