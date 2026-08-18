<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Webhooks;

/**
 * @phpstan-type EnvelopeArray array{
 *     event: string,
 *     provider: string,
 *     account_id: string,
 *     entity_id?: string,
 *     action?: string,
 *     occurred_at: string|null,
 *     hub_authored?: true,
 *     hub_last_wrote_at?: string,
 *     data: array<string, mixed>
 * }
 */
final class HubWebhookEnvelope
{
    /**
     * @param  array<string, mixed>  $data
     * @param  bool  $hubAuthored  True when Hub has ever written this entity —
     *                             not when Hub wrote this change. Pair it with
     *                             $hubLastWroteAt, or use {@see self::isOwnEcho()}.
     * @param  string|null  $entityId  The provider's own id for the changed
     *                                 entity: the same id Hub returned when you
     *                                 booked it. Null when the provider carries
     *                                 no id this SDK release can read.
     * @param  string|null  $hubLastWroteAt  When Hub last wrote this entity.
     */
    public function __construct(
        public readonly HubWebhookEvent $event,
        public readonly string $provider,
        public readonly string $accountId,
        public readonly ?string $occurredAt,
        public readonly array $data,
        public readonly bool $hubAuthored = false,
        public readonly ?string $entityId = null,
        public readonly ?HubWebhookAction $action = null,
        public readonly ?string $hubLastWroteAt = null,
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

    /** @param  array<string, mixed>  $payload */
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
            hubAuthored: ($payload['hub_authored'] ?? null) === true,
            entityId: self::text($payload['entity_id'] ?? null),
            action: HubWebhookAction::tryFromWire($payload['action'] ?? null),
            hubLastWroteAt: self::text($payload['hub_last_wrote_at'] ?? null),
        );
    }

    public function isOwnEcho(int $seconds = 300): bool
    {
        if (! $this->hubAuthored || $this->hubLastWroteAt === null || $this->occurredAt === null) {
            return false;
        }

        $wroteAt = strtotime($this->hubLastWroteAt);
        $occurredAt = strtotime($this->occurredAt);

        if ($wroteAt === false || $occurredAt === false || $occurredAt < $wroteAt) {
            return false;
        }

        return ($occurredAt - $wroteAt) <= $seconds;
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /** @return EnvelopeArray */
    public function toArray(): array
    {
        $payload = [
            'event' => $this->event->value,
            'provider' => $this->provider,
            'account_id' => $this->accountId,
            'occurred_at' => $this->occurredAt,
            'data' => $this->data,
        ];

        if ($this->entityId !== null) {
            $payload['entity_id'] = $this->entityId;
        }

        if ($this->action !== null) {
            $payload['action'] = $this->action->value;
        }

        if ($this->hubAuthored) {
            $payload['hub_authored'] = true;
        }

        if ($this->hubLastWroteAt !== null) {
            $payload['hub_last_wrote_at'] = $this->hubLastWroteAt;
        }

        return $payload;
    }
}
