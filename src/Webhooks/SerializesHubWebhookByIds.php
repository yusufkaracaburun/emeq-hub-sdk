<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Webhooks;

use Spatie\WebhookClient\Models\WebhookCall;

/**
 * Queue serialization for multi-DB consumers: persist only account + call ids,
 * never the WebhookCall Eloquent model (wrong connection after worker reboot).
 *
 * Use on a subclass of {@see ProcessHubWebhookJob}, which owns `$accountId` /
 * `$webhookCallId` and reloads the stripped model in resolveWebhookCall().
 */
trait SerializesHubWebhookByIds
{
    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        // Omit `job` — the queue worker rebinds it after unserialize.
        return [
            'accountId' => $this->accountId,
            'webhookCallId' => $this->webhookCallId,
            'connection' => $this->connection,
            'queue' => $this->queue,
            'chainConnection' => $this->chainConnection,
            'chainQueue' => $this->chainQueue,
            'chainCatchCallbacks' => $this->chainCatchCallbacks,
            'delay' => $this->delay,
            'afterCommit' => $this->afterCommit,
            'middleware' => $this->middleware,
            'chained' => $this->chained,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        $this->accountId = (string) ($data['accountId'] ?? '');
        $this->webhookCallId = (int) ($data['webhookCallId'] ?? 0);
        $this->connection = $data['connection'] ?? null;
        $this->queue = $data['queue'] ?? null;
        $this->chainConnection = $data['chainConnection'] ?? null;
        $this->chainQueue = $data['chainQueue'] ?? null;
        $this->chainCatchCallbacks = $data['chainCatchCallbacks'] ?? null;
        $this->delay = $data['delay'] ?? null;
        $this->afterCommit = $data['afterCommit'] ?? null;
        $this->middleware = $data['middleware'] ?? [];
        $this->chained = $data['chained'] ?? [];

        $this->webhookCall = new WebhookCall(['id' => $this->webhookCallId]);
        $this->webhookCall->exists = true;
    }
}
