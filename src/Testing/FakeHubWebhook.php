<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Testing;

use Emeq\HubSdk\Webhooks\HubWebhookAction;
use Emeq\HubSdk\Webhooks\HubWebhookEnvelope;
use Emeq\HubSdk\Webhooks\HubWebhookEvent;
use Emeq\HubSdk\Webhooks\HubWebhookHeaders;
use Illuminate\Support\Str;

final class FakeHubWebhook
{
    private function __construct(
        private readonly HubWebhookEnvelope $envelope,
        private readonly string $eventId,
        private readonly string $requestId,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function event(
        HubWebhookEvent $event,
        string $accountId,
        array $data = [],
        string $provider = 'exact',
        ?string $occurredAt = null,
        bool $hubAuthored = false,
        ?string $eventId = null,
        ?string $requestId = null,
        ?string $entityId = null,
        ?HubWebhookAction $action = null,
        ?string $hubLastWroteAt = null,
    ): self {
        return new self(
            envelope: new HubWebhookEnvelope(
                event: $event,
                provider: $provider,
                accountId: $accountId,
                occurredAt: $occurredAt,
                data: $data,
                hubAuthored: $hubAuthored,
                entityId: $entityId,
                action: $action,
                hubLastWroteAt: $hubLastWroteAt,
            ),
            eventId: $eventId ?? (string) Str::uuid(),
            requestId: $requestId ?? (string) Str::uuid(),
        );
    }

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

    public static function extractionRunCompleted(string $accountId, string $runId = 'run_test'): self
    {
        return self::event(
            HubWebhookEvent::EXTRACTION_RUN_COMPLETED,
            accountId: $accountId,
            data: [
                'run_id' => $runId,
                'profile' => 'timesheet',
                'version' => 1,
                'readers' => ['ministral-14b-latest', 'mistral-medium-2604'],
                'result' => [
                    'employee' => 'Fatima Karim',
                    'rows' => [
                        ['day' => 'ma', 'hours' => 8],
                        ['day' => 'di', 'hours' => null],
                    ],
                ],
                'signals' => [
                    'differs' => [
                        ['pointer' => '/rows/0/hours', 'alternatives' => [7]],
                    ],
                    'rows_only_in_second_read' => [
                        ['day' => 'wo', 'hours' => 4],
                    ],
                    'review' => ['/rows/0/hours'],
                ],
                'pages' => 1,
            ],
            provider: 'extraction',
        );
    }

    public static function extractionRunFailed(
        string $accountId,
        string $runId = 'run_test',
        string $reason = 'schema_invalid',
    ): self {
        return self::event(
            HubWebhookEvent::EXTRACTION_RUN_FAILED,
            accountId: $accountId,
            data: [
                'run_id' => $runId,
                'profile' => 'timesheet',
                'version' => 1,
                'reason' => $reason,
            ],
            provider: 'extraction',
        );
    }

    public static function salesInvoiceChanged(
        string $accountId,
        string $externalRef = 'ext-test',
        bool $hubAuthored = false,
        string $provider = 'exact',
        ?string $entityId = null,
        ?HubWebhookAction $action = HubWebhookAction::UPDATED,
        ?string $hubLastWroteAt = null,
    ): self {
        return self::event(
            HubWebhookEvent::SALES_INVOICE_CHANGED,
            accountId: $accountId,
            data: ['external_ref' => $externalRef],
            hubAuthored: $hubAuthored,
            provider: $provider,
            entityId: $entityId,
            action: $action,
            hubLastWroteAt: $hubLastWroteAt,
        );
    }

    public function envelope(): HubWebhookEnvelope
    {
        return $this->envelope;
    }

    public function body(): string
    {
        return (string) json_encode($this->envelope->toArray(), JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> */
    public function headers(string $secret): array
    {
        return [
            HubWebhookHeaders::SIGNATURE => hash_hmac('sha256', $this->body(), $secret),
            HubWebhookHeaders::EVENT_ID => $this->eventId,
            HubWebhookHeaders::REQUEST_ID => $this->requestId,
        ];
    }
}
