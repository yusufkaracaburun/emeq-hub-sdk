<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Webhooks;

use Emeq\HubSdk\Events\HubConnectionRevoked;
use Emeq\HubSdk\Events\HubWebhookIgnored;
use Emeq\HubSdk\Events\HubWebhookReceived;
use Illuminate\Support\Facades\Log;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob;
use Spatie\WebhookClient\Models\WebhookCall;

/**
 * Shared Hub webhook processing: context bind → reload → dedupe → event dispatch.
 *
 * Single-DB apps can use this class as-is (or extend only hooks).
 * Multi-DB apps override bind/release/resolveWebhookCall and usually
 * {@see SerializesHubWebhookByIds}.
 */
class ProcessHubWebhookJob extends ProcessWebhookJob
{
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

            if ($eventId !== null && $this->alreadyProcessed($eventId, (int) $call->getKey())) {
                Log::info('hub.webhook.deduplicated', [
                    'event_id' => $eventId,
                    'account_id' => $accountId,
                    'webhook_call_id' => $call->getKey(),
                ]);

                return;
            }

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
            'event' => $envelope->event,
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
            'event' => $envelope->event,
            'provider' => $envelope->provider,
            'account_id' => $envelope->accountId,
            'request_id' => $requestId,
            'event_id' => $eventId,
        ]);

        event(new HubWebhookIgnored($envelope, $eventId, $requestId));
    }

    /**
     * Spatie stores Symfony headers (lowercased keys, array values).
     */
    protected function alreadyProcessed(string $eventId, int $currentId): bool
    {
        $headerKey = strtolower(HubWebhookHeaders::EVENT_ID);

        return WebhookCall::query()
            ->where('name', $this->webhookConfigName())
            ->where('id', '<', $currentId)
            ->whereNull('exception')
            ->where(function ($query) use ($headerKey, $eventId): void {
                $query
                    ->where("headers->{$headerKey}", $eventId)
                    ->orWhere("headers->{$headerKey}[0]", $eventId)
                    ->orWhereJsonContains("headers->{$headerKey}", $eventId);
            })
            ->exists();
    }

    protected function webhookConfigName(): string
    {
        return 'emeq-hub';
    }
}
