<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Booking;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

    public const STATUS_POSTED = 'posted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNKNOWN = 'unknown';

    private const POSTED_FIRST = 'CASE WHEN status = ? THEN 0 ELSE 1 END';

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

        return self::$tracesRequests ??= Schema::connection($model->getConnectionName())
            ->hasColumns($model->getTable(), self::TRACE_COLUMNS);
    }

    public static function forgetTraceSupport(): void
    {
        self::$tracesRequests = null;
    }

    /**
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
