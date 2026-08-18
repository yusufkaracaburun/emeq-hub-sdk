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

class HubWebhookDeduplicator
{
    /**
     * @param  list<string>  $opaqueEventIds  X-Emeq-Event-Id values Hub reuses
     *                                        across unrelated events, so they
     *                                        identify nothing. See {@see identityFor()}.
     */
    public function __construct(
        protected readonly string $configName,
        protected readonly string $accountId,
        protected readonly array $opaqueEventIds = ['no-id'],
    ) {}

    public function identityFor(?string $eventId): ?string
    {
        if ($eventId === null) {
            return null;
        }

        return in_array($eventId, $this->opaqueEventIds, true) ? null : $eventId;
    }

    /** @throws MissingConfigurationException when the store cannot lock */
    public function lock(string $eventId): Lock
    {
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

    protected function lockKey(string $eventId): string
    {
        return 'hub-webhook:'.$this->configName.':'.$this->accountId.':'.$eventId;
    }

    /** @param  Builder<WebhookCall>  $query */
    protected function whereAccountId(Builder $query): void
    {
        $accountId = $this->accountId;

        $query->where(function (Builder $query) use ($accountId): void {
            $query->where('payload->account_id', $accountId);

            if (ctype_digit($accountId)) {
                $query->orWhere('payload->account_id', (int) $accountId);
            }
        });
    }
}
