<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Webhooks;

use Emeq\HubSdk\Events\HubConnectionRevoked;
use Emeq\HubSdk\Events\HubWebhookHandled;
use Emeq\HubSdk\Events\HubWebhookIgnored;
use Emeq\HubSdk\Events\HubWebhookReceived;
use Exception;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob;
use Spatie\WebhookClient\Models\WebhookCall;
use Throwable;

class ProcessHubWebhookJob extends ProcessWebhookJob
{
    public int $tries = 3;

    public int $backoff = 30;

    public string $accountId = '';

    public int $webhookCallId = 0;

    public function __construct(WebhookCall $webhookCall)
    {
        parent::__construct($webhookCall);

        $payload = is_array($webhookCall->payload) ? $webhookCall->payload : [];
        $accountId = $payload['account_id'] ?? null;

        $this->accountId = is_scalar($accountId) ? (string) $accountId : '';
        $this->webhookCallId = self::keyOf($webhookCall);
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
            $dedupe = $this->deduplicator();
            $dedupeId = $dedupe->identityFor($eventId);

            if ($dedupeId === null) {
                $this->processCall($call, $eventId, $requestId, $accountId);

                return;
            }

            $lock = $dedupe->lock($dedupeId);

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
                if ($dedupe->alreadyProcessed($dedupeId, self::keyOf($call))) {
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

    protected function deduplicator(): HubWebhookDeduplicator
    {
        return new HubWebhookDeduplicator($this->webhookConfigName(), $this->accountId);
    }

    private static function keyOf(WebhookCall $call): int
    {
        $key = $call->getKey();

        return is_numeric($key) ? (int) $key : 0;
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }

        if (! $this->bindAccountContext($this->accountId)) {
            $this->logUnrecordedFailure('unknown_account_in_failed', $exception);

            return;
        }

        try {
            $call = $this->resolveWebhookCall();

            if ($call === null) {
                $this->logUnrecordedFailure('webhook_call_missing_in_failed', $exception);

                return;
            }

            $call->saveException(
                $exception instanceof Exception
                    ? $exception
                    : new RuntimeException($exception->getMessage(), (int) $exception->getCode(), $exception),
            );
        } finally {
            $this->releaseAccountContext();
        }
    }

    private function logUnrecordedFailure(string $reason, Throwable $exception): void
    {
        Log::error('hub.webhook.failure_unrecorded', [
            'reason' => $reason,
            'account_id' => $this->accountId,
            'webhook_call_id' => $this->webhookCallId,
            'exception' => $exception->getMessage(),
        ]);
    }

    protected function bindAccountContext(string $accountId): bool
    {
        return true;
    }

    protected function releaseAccountContext(): void {}

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

        if (in_array($envelope->event, $this->handles(), true)) {
            $this->onEvent($envelope, $eventId, $requestId);

            return;
        }

        $this->onIgnored($envelope, $eventId, $requestId);
    }

    /** @return list<HubWebhookEvent> */
    protected function handles(): array
    {
        return [];
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

    protected function onEvent(
        HubWebhookEnvelope $envelope,
        ?string $eventId,
        ?string $requestId,
    ): void {
        Log::info('hub.webhook.handled', [
            'event' => $envelope->event->value,
            'provider' => $envelope->provider,
            'account_id' => $envelope->accountId,
            'request_id' => $requestId,
            'event_id' => $eventId,
        ]);

        event(new HubWebhookHandled($envelope, $eventId, $requestId));
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

    protected function webhookConfigName(): string
    {
        $name = config('hub.webhook.name', 'emeq-hub');

        return is_string($name) && $name !== '' ? $name : 'emeq-hub';
    }
}
