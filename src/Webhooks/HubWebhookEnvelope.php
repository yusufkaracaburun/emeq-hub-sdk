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
 *     caused_by_hub?: true,
 *     data: array<string, mixed>
 * }
 */
final class HubWebhookEnvelope
{
    /**
     * @param  array<string, mixed>  $data
     * @param  bool  $causedByHub  True when Hub has ever authored this entity —
     *                             not when Hub caused this specific change. A
     *                             human edit on a Hub-booked document still
     *                             arrives flagged true. See "caused_by_hub"
     *                             in docs/webhooks.md before branching on it.
     */
    public function __construct(
        public readonly HubWebhookEvent $event,
        public readonly string $provider,
        public readonly string $accountId,
        public readonly ?string $occurredAt,
        public readonly array $data,
        public readonly bool $causedByHub = false,
    ) {}

    public static function tryFromRaw(string $rawBody): ?self
    {
        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            return null;
        }

        /** @var array<string, mixed> $payload */
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
            event: HubWebhookEvent::fromWire($payload['event'] ?? null),
            provider: self::text($payload['provider'] ?? null) ?? '',
            accountId: self::text($accountId) ?? '',
            occurredAt: self::text($payload['occurred_at'] ?? null),
            data: is_array($data) ? $data : [],
            causedByHub: ($payload['caused_by_hub'] ?? null) === true,
        );
    }

    /**
     * Webhook bodies are untrusted: a non-scalar reads as absent.
     */
    private static function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @return EnvelopeArray
     */
    public function toArray(): array
    {
        $payload = [
            'event' => $this->event->value,
            'provider' => $this->provider,
            'account_id' => $this->accountId,
            'occurred_at' => $this->occurredAt,
            'data' => $this->data,
        ];

        if ($this->causedByHub) {
            $payload['caused_by_hub'] = true;
        }

        return $payload;
    }
}
