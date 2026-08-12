<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Webhooks;

use Emeq\HubSdk\Events\HubConnectionRevoked;
use Emeq\HubSdk\Events\HubWebhookIgnored;
use Emeq\HubSdk\Events\HubWebhookReceived;
use Emeq\HubSdk\Exceptions\MissingConfigurationException;
use Exception;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob;
use Spatie\WebhookClient\Models\WebhookCall;
use Throwable;

/**
 * Shared Hub webhook processing: context bind → reload → dedupe → event dispatch.
 *
 * Single-DB apps can use this class as-is (or extend only hooks).
 * Multi-DB apps override bind/release/resolveWebhookCall and usually
 * {@see SerializesHubWebhookByIds}.
 */
class ProcessHubWebhookJob extends ProcessWebhookJob
{
    /**
     * Explicit retry policy: without one the package inherits whatever the
     * host's queue worker was started with, which is not a contract.
     */
    public int $tries = 3;

    public int $backoff = 30;

    public string $accountId = '';

    public int $webhookCallId = 0;

    public function __construct(WebhookCall $webhookCall)
    {
        parent::__construct($webhookCall);

        $payload = is_array($webhookCall->payload) ? $webhookCall->payload : [];
        $this->accountId = (string) ($payload['account_id'] ?? '');
        $this->webhookCallId = (int) $webhookCall->getKey();
    }

    public function handle(): void
    {
        $accountId = $this->accountId;

        try {
            if ($accountId === '' || ! $this->bindAccountContext($accountId)) {
                Log::info('hub.webhook.skipped', [
                    'reason' => 'unknown_account_in_job',
                    'account_id' => $accountId,
                    'webhook_call_id' => $this->webhookCall->getKey(),
                ]);

                return;
            }

            $call = $this->resolveWebhookCall();
            if ($call === null) {
                Log::info('hub.webhook.skipped', [
                    'reason' => 'webhook_call_missing',
                    'account_id' => $accountId,
                    'webhook_call_id' => $this->webhookCall->getKey(),
                ]);

                return;
            }

            $this->webhookCall = $call;

            $headers = is_array($call->headers) ? $call->headers : [];
            $eventId = HubWebhookHeaders::eventId($headers);
            $requestId = HubWebhookHeaders::requestId($headers);

            if ($eventId === null) {
                $this->processCall($call, null, $requestId, $accountId);

                return;
            }

            // Dedupe is check-then-act, so two workers holding concurrent
            // redeliveries of one event would both pass it. The lock makes the
            // pair sequential; the loser then sees the winner's row.
            $lock = $this->deduplicationLock($eventId);

            if (! $lock->get()) {
                Log::info('hub.webhook.deduplicated', [
                    'reason' => 'concurrent_delivery',
                    'event_id' => $eventId,
                    'account_id' => $accountId,
                    'webhook_call_id' => $call->getKey(),
                ]);

                return;
            }

            try {
                if ($this->alreadyProcessed($eventId, (int) $call->getKey())) {
                    Log::info('hub.webhook.deduplicated', [
                        'event_id' => $eventId,
                        'account_id' => $accountId,
                        'webhook_call_id' => $call->getKey(),
                    ]);

                    return;
                }

                $this->processCall($call, $eventId, $requestId, $accountId);
            } finally {
                $lock->release();
            }
        } finally {
            $this->releaseAccountContext();
        }
    }

    protected function processCall(
        WebhookCall $call,
        ?string $eventId,
        ?string $requestId,
        string $accountId,
    ): void {
        $envelope = HubWebhookEnvelope::tryFromArray(
            is_array($call->payload) ? $call->payload : []
        );

        if ($envelope === null) {
            Log::info('hub.webhook.skipped', [
                'reason' => 'invalid_payload_in_job',
                'account_id' => $accountId,
                'webhook_call_id' => $call->getKey(),
            ]);

            return;
        }

        $this->processEnvelope($envelope, $eventId, $requestId);
    }

    /**
     * The configured store must support atomic locks. Refusing here rather than
     * degrading to an unguarded check keeps the failure visible: the job lands
     * in failed_jobs with the reason instead of silently racing.
     *
     * @throws MissingConfigurationException when the store cannot lock
     */
    protected function deduplicationLock(string $eventId): Lock
    {
        $configured = config('hub.webhook.lock_store');
        $name = is_string($configured) && $configured !== '' ? $configured : null;

        $store = Cache::store($name)->getStore();

        if (! $store instanceof LockProvider) {
            throw MissingConfigurationException::webhookLockStoreNotLockable($name ?? (string) config('cache.default'));
        }

        return $store->lock($this->deduplicationLockKey($eventId), 30);
    }

    /**
     * Scoped per webhook config so two Hub configs cannot block each other.
     */
    protected function deduplicationLockKey(string $eventId): string
    {
        return 'hub-webhook:'.$this->webhookConfigName().':'.$eventId;
    }

    /**
     * Records the failure on the webhook_calls row.
     *
     * Spatie only writes `exception` from the synchronous request path, so
     * without this a crashed job leaves the column null — and alreadyProcessed()
     * would then read that row as "handled" and drop Hub's redelivery.
     */
    public function failed(?Throwable $exception): void
    {
        if ($exception === null || ! $this->bindAccountContext($this->accountId)) {
            return;
        }

        try {
            $this->resolveWebhookCall()?->saveException(
                $exception instanceof Exception
                    ? $exception
                    : new RuntimeException($exception->getMessage(), (int) $exception->getCode(), $exception),
            );
        } finally {
            $this->releaseAccountContext();
        }
    }

    /**
     * Switch tenant / DB before reading webhook_calls. Return false to abort.
     */
    protected function bindAccountContext(string $accountId): bool
    {
        return true;
    }

    protected function releaseAccountContext(): void
    {
        //
    }

    /**
     * Single-DB: the model Spatie hydrated is already complete.
     *
     * After {@see SerializesHubWebhookByIds} restores a job from the queue the
     * model carries only its id, so it is reloaded here — which runs *after*
     * bindAccountContext(), i.e. on the right connection for multi-DB consumers.
     */
    protected function resolveWebhookCall(): ?WebhookCall
    {
        if ($this->webhookCall->payload !== null) {
            return $this->webhookCall;
        }

        return WebhookCall::query()->find($this->webhookCallId);
    }

    protected function processEnvelope(
        HubWebhookEnvelope $envelope,
        ?string $eventId,
        ?string $requestId,
    ): void {
        event(new HubWebhookReceived($envelope, $eventId, $requestId));

        if ($envelope->event === HubWebhookEvent::CONNECTION_REVOKED) {
            $this->onConnectionRevoked($envelope, $eventId, $requestId);

            return;
        }

        $this->onIgnored($envelope, $eventId, $requestId);
    }

    protected function onConnectionRevoked(
        HubWebhookEnvelope $envelope,
        ?string $eventId,
        ?string $requestId,
    ): void {
        Log::info('hub.webhook.connection_revoked', [
            'event' => $envelope->event->value,
            'provider' => $envelope->provider,
            'account_id' => $envelope->accountId,
            'request_id' => $requestId,
            'event_id' => $eventId,
            'data' => $envelope->data,
        ]);

        event(new HubConnectionRevoked($envelope, $eventId, $requestId));
    }

    protected function onIgnored(
        HubWebhookEnvelope $envelope,
        ?string $eventId,
        ?string $requestId,
    ): void {
        Log::info('hub.webhook.ignored', [
            'event' => $envelope->event->value,
            'provider' => $envelope->provider,
            'account_id' => $envelope->accountId,
            'request_id' => $requestId,
            'event_id' => $eventId,
        ]);

        event(new HubWebhookIgnored($envelope, $eventId, $requestId));
    }

    /**
     * An earlier call with this event id that did not record an exception —
     * i.e. one that ran to completion.
     */
    protected function alreadyProcessed(string $eventId, int $currentId): bool
    {
        $query = WebhookCall::query()
            ->where('name', $this->webhookConfigName())
            ->where('id', '<', $currentId)
            ->whereNull('exception');

        HubWebhookHeaders::whereEventId($query, $eventId);

        return $query->exists();
    }

    protected function webhookConfigName(): string
    {
        return 'emeq-hub';
    }
}
