<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Webhooks;

use Emeq\HubSdk\Exceptions\MissingConfigurationException;
use Emeq\HubSdk\Support\HubLocks;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Eloquent\Builder;
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
        return HubLocks::webhookStore()->lock($this->lockKey($eventId), $this->lockSeconds());
    }

    public function lockSeconds(): int
    {
        return HubLocks::webhookSeconds();
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
