<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Webhooks;

use Emeq\HubSdk\Exceptions\MissingConfigurationException;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Spatie\WebhookClient\Models\WebhookCall;

/**
 * Decides whether a Hub delivery has already been handled, and serializes the
 * concurrent case.
 *
 * Split out of {@see ProcessHubWebhookJob} so the job a consumer subclasses
 * stays about bind → resolve → dispatch. Override
 * {@see ProcessHubWebhookJob::deduplicator()} to swap a subclass of this in.
 */
class HubWebhookDeduplicator
{
    /**
     * X-Emeq-Event-Id values Hub reuses across unrelated events, so they
     * identify nothing. See {@see identityFor()}.
     *
     * @var list<string>
     */
    protected const OPAQUE_EVENT_IDS = ['no-id'];

    public function __construct(
        protected readonly string $configName,
        protected readonly string $accountId,
    ) {}

    /**
     * The event id to deduplicate on, or null when the header carries no usable
     * identity.
     *
     * Hub mints an id per delivery, but only when the partner supplies one:
     * Snelstart's controller falls back to the literal string `no-id`, so many
     * unrelated events arrive sharing it. Treating that as an identity makes the
     * first one swallow all the rest. Such values are handled exactly like a
     * missing header: processed, never deduplicated. Subclasses recognise
     * further sentinels by overriding {@see OPAQUE_EVENT_IDS}.
     */
    public function identityFor(?string $eventId): ?string
    {
        if ($eventId === null) {
            return null;
        }

        return in_array($eventId, static::OPAQUE_EVENT_IDS, true) ? null : $eventId;
    }

    /**
     * The configured store must support atomic locks. Refusing here rather than
     * degrading to an unguarded check keeps the failure visible: the job lands
     * in failed_jobs with the reason instead of silently racing.
     *
     * @throws MissingConfigurationException when the store cannot lock
     */
    public function lock(string $eventId): Lock
    {
        // Config::string() would throw on the null this key legitimately holds.
        $configured = Config::get('hub.webhook.lock_store');
        $name = is_string($configured) && $configured !== '' ? $configured : null;

        $store = Cache::store($name)->getStore();

        if (! $store instanceof LockProvider) {
            $default = Config::get('cache.default');

            throw MissingConfigurationException::webhookLockStoreNotLockable(
                $name ?? (is_string($default) ? $default : 'default'),
            );
        }

        return $store->lock($this->lockKey($eventId), 30);
    }

    /**
     * An earlier call with this event id that did not record an exception —
     * i.e. one that ran to completion.
     */
    public function alreadyProcessed(string $eventId, int $currentId): bool
    {
        $query = WebhookCall::query()
            ->where('name', $this->configName)
            ->where('id', '<', $currentId)
            ->whereNull('exception');

        $this->whereAccountId($query);
        HubWebhookHeaders::whereEventId($query, $eventId);

        return $query->exists();
    }

    /**
     * Scoped per webhook config so two Hub configs cannot block each other, and
     * per account so the lock is no wider than the guard it protects: two
     * accounts delivered one event id must not skip each other as duplicates.
     */
    protected function lockKey(string $eventId): string
    {
        return 'hub-webhook:'.$this->configName.':'.$this->accountId.':'.$eventId;
    }

    /**
     * Multi-DB consumers already read a per-tenant table, but the single-DB
     * default shares one — so without this the event id alone decides, and one
     * account's delivery deduplicates another's.
     *
     * @param  Builder<WebhookCall>  $query
     */
    protected function whereAccountId(Builder $query): void
    {
        $accountId = $this->accountId;

        $query->where(function (Builder $query) use ($accountId): void {
            $query->where('payload->account_id', $accountId);

            // SQLite compares a JSON number against a bound string as unequal.
            if (ctype_digit($accountId)) {
                $query->orWhere('payload->account_id', (int) $accountId);
            }
        });
    }
}
