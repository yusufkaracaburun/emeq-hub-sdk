<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Testing;

use Emeq\HubSdk\Webhooks\HubWebhookEnvelope;
use Emeq\HubSdk\Webhooks\HubWebhookEvent;
use Emeq\HubSdk\Webhooks\HubWebhookHeaders;
use Emeq\HubSdk\Webhooks\SpatieWebhookClientConfig;
use Illuminate\Support\Str;

/**
 * A signed inbound Hub webhook, for consumer test suites.
 *
 * Owns the same knowledge {@see SpatieWebhookClientConfig},
 * {@see HubWebhookHeaders} and {@see HubWebhookEnvelope::toArray()} already
 * carry, so a consumer building a request no longer hand-rolls
 * `hash_hmac('sha256', $body, $secret)` next to an invented envelope array.
 *
 * ```php
 * $fake = FakeHubWebhook::salesInvoiceChanged(accountId: '47');
 *
 * $this->postJson('/webhooks/emeq-hub', json_decode($fake->body(), true), $fake->headers($secret));
 * ```
 */
final class FakeHubWebhook
{
    private function __construct(
        private readonly HubWebhookEnvelope $envelope,
        private readonly string $eventId,
        private readonly string $requestId,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function event(
        HubWebhookEvent $event,
        string $accountId,
        array $data = [],
        string $provider = 'exact',
        ?string $occurredAt = null,
        bool $causedByHub = false,
        ?string $eventId = null,
        ?string $requestId = null,
    ): self {
        return new self(
            envelope: new HubWebhookEnvelope(
                event: $event,
                provider: $provider,
                accountId: $accountId,
                occurredAt: $occurredAt,
                data: $data,
                causedByHub: $causedByHub,
            ),
            eventId: $eventId ?? (string) Str::uuid(),
            requestId: $requestId ?? (string) Str::uuid(),
        );
    }

    /**
     * `connection.revoked` — the shape a disconnect sends.
     */
    public static function connectionRevoked(
        string $accountId,
        string $connectionId = 'con_test',
        string $provider = 'exact',
    ): self {
        return self::event(
            HubWebhookEvent::CONNECTION_REVOKED,
            accountId: $accountId,
            data: ['connection_id' => $connectionId],
            provider: $provider,
        );
    }

    /**
     * `accounting.sales_invoice.changed`. `data` is illustrative only — Hub
     * passes the provider's own webhook payload through unparsed, and this
     * package does not mirror that shape.
     */
    public static function salesInvoiceChanged(
        string $accountId,
        string $externalRef = 'ext-test',
        bool $causedByHub = false,
        string $provider = 'exact',
    ): self {
        return self::event(
            HubWebhookEvent::SALES_INVOICE_CHANGED,
            accountId: $accountId,
            data: ['external_ref' => $externalRef],
            causedByHub: $causedByHub,
            provider: $provider,
        );
    }

    public function envelope(): HubWebhookEnvelope
    {
        return $this->envelope;
    }

    /**
     * The exact raw body a consumer must sign and post — decodes back to an
     * identical envelope via {@see HubWebhookEnvelope::tryFromRaw()}.
     */
    public function body(): string
    {
        return (string) json_encode($this->envelope->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, string>
     */
    public function headers(string $secret): array
    {
        return [
            HubWebhookHeaders::SIGNATURE => hash_hmac('sha256', $this->body(), $secret),
            HubWebhookHeaders::EVENT_ID => $this->eventId,
            HubWebhookHeaders::REQUEST_ID => $this->requestId,
        ];
    }
}
