<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Webhooks;

/**
 * Body shape of every Hub → consumer webhook.
 *
 * @phpstan-type EnvelopeArray array{
 *     event: string,
 *     provider: string,
 *     account_id: string,
 *     occurred_at: string|null,
 *     data: array<string, mixed>
 * }
 */
final class HubWebhookEnvelope
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $event,
        public readonly string $provider,
        public readonly string $accountId,
        public readonly ?string $occurredAt,
        public readonly array $data,
    ) {
    }

    public static function tryFromRaw(string $rawBody): ?self
    {
        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            return null;
        }

        return self::tryFromArray($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function tryFromArray(array $payload): ?self
    {
        $accountId = $payload['account_id'] ?? null;

        if ($accountId === null || $accountId === '') {
            return null;
        }

        $data = $payload['data'] ?? [];

        return new self(
            event: (string) ($payload['event'] ?? HubWebhookEvent::UNMAPPED),
            provider: (string) ($payload['provider'] ?? ''),
            accountId: (string) $accountId,
            occurredAt: isset($payload['occurred_at']) ? (string) $payload['occurred_at'] : null,
            data: is_array($data) ? $data : [],
        );
    }

    /**
     * @return EnvelopeArray
     */
    public function toArray(): array
    {
        return [
            'event' => $this->event,
            'provider' => $this->provider,
            'account_id' => $this->accountId,
            'occurred_at' => $this->occurredAt,
            'data' => $this->data,
        ];
    }
}
