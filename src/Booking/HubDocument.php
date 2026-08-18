<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Booking;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * What the bookkeeping did with one document.
 *
 * Keyed the way Hub defines a document's identity — (account, type,
 * external_id) — so one row covers every kind of document a consumer books,
 * without a foreign key to any of them.
 *
 * A record of what this consumer sent and what Hub answered, not a mirror of
 * the bookkeeping. See ADR-0003.
 *
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
    /**
     * What Hub called this failure, kept next to the row it decided.
     *
     * Optional on purpose: they arrive with a migration the consumer publishes
     * when it suits them (ADR-0002), so everything here has to work without
     * them. See {@see self::withoutMissingTrace()}.
     *
     * @var list<string>
     */
    public const TRACE_COLUMNS = ['request_id', 'category'];

    public const STATUS_POSTED = 'posted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    /**
     * The send to the bookkeeping was interrupted (e.g. a connect/read
     * timeout) and its outcome is not known. Hub may have received and posted
     * the document anyway; only a manual check can tell.
     */
    public const STATUS_UNKNOWN = 'unknown';

    /**
     * One external_id can carry more than one row, because the type changed
     * between attempts. The posted row describes the document; without this
     * order a read can hand back its non-posted sibling, which skips the "do
     * not resend" guard and demotes a document that is already booked.
     */
    private const POSTED_FIRST = 'CASE WHEN status = ? THEN 0 ELSE 1 END';

    /** @see self::tracesRequests() */
    private static ?bool $tracesRequests = null;

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
        // Providers send the document number as an integer; it is a label, not a sum.
        'external_number' => 'string',
        'booked_at' => 'datetime',
        'accounting_changed_at' => 'datetime',
    ];

    /**
     * What Hub reported this attempt did to the relation — `relation.created`,
     * `relation.matched_by_name`, `relation.name_differs`. Not an Eloquent
     * attribute: only the attempt that booked the document can report them, so
     * a row read back later carries none rather than a stale answer.
     *
     * @var list<array<string, mixed>>
     */
    public array $warnings = [];

    /**
     * Declares no connection of its own: a ledger read against the wrong
     * database answers "not booked yet", and the next run posts a duplicate
     * into a real administration. Consumers that keep the ledger off their
     * default connection say so once, in `hub.booking.connection`.
     */
    public function getConnectionName(): ?string
    {
        $connection = config('hub.booking.connection');

        return is_string($connection) && $connection !== ''
            ? $connection
            : parent::getConnectionName();
    }

    /**
     * The given attributes, minus the trace this ledger has no columns for.
     *
     * ADR-0003 says a consumer on an older stub keeps working, which held for
     * reads — the model never selects columns explicitly — but not for writes: a
     * fill of a column that is not there fails at the database. This closes that
     * half, so publishing the trace migration stays the consumer's call and a
     * booking never fails over a column that only exists to be looked at later.
     *
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

    /**
     * Whether this ledger carries the trace columns.
     *
     * Resolved once: the answer cannot change while the process runs, and this
     * is a schema read on the booking path.
     */
    public static function tracesRequests(): bool
    {
        $model = new self;

        return self::$tracesRequests ??= Schema::connection($model->getConnectionName())
            ->hasColumns($model->getTable(), self::TRACE_COLUMNS);
    }

    /**
     * Drops the cached schema answer. For tests that add or remove the columns
     * within one process; nothing in an application changes its own schema.
     */
    public static function forgetTraceSupport(): void
    {
        self::$tracesRequests = null;
    }

    /**
     * The booking of each given document, keyed by its external id, scoped to
     * one account: the same external_id can belong to an unrelated document in
     * a different administration after a re-coupling.
     *
     * Not keyed by type as well: a caller holds a document, not a Hub type, and
     * one invoice is a sales_invoice or a credit_note depending on its total.
     * Siblings collapse under {@see self::POSTED_FIRST}.
     *
     * @param  list<string>  $externalIds
     * @return Collection<string, static>
     */
    public static function forExternalIds(array $externalIds, string $accountId): Collection
    {
        $documents = static::query()
            ->where('account_id', $accountId)
            ->whereIn('external_id', array_filter($externalIds))
            ->orderByRaw(self::POSTED_FIRST, [self::STATUS_POSTED])
            ->orderByDesc('id')
            ->get();

        $byExternalId = [];

        foreach ($documents as $document) {
            $byExternalId[$document->external_id] ??= $document;
        }

        return new Collection($byExternalId);
    }

    /**
     * The row a new booking attempt continues, under the same precedence as
     * {@see self::forExternalIds()}.
     *
     * Returns an unsaved row when this document has never been attempted.
     */
    public static function forBooking(string $externalId, string $accountId): self
    {
        $existing = static::query()
            ->where('account_id', $accountId)
            ->where('external_id', $externalId)
            ->orderByRaw(self::POSTED_FIRST, [self::STATUS_POSTED])
            ->orderByDesc('id')
            ->first();

        return $existing ?? static::make([
            'account_id' => $accountId,
            'external_id' => $externalId,
        ]);
    }
}
