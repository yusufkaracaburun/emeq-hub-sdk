<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Events;

use Emeq\HubSdk\Webhooks\HubWebhookEnvelope;
use Emeq\HubSdk\Webhooks\ProcessHubWebhookJob;

/**
 * Dispatched alongside {@see ProcessHubWebhookJob::onEvent()},
 * for an event the job's {@see ProcessHubWebhookJob::handles()}
 * claims. Mirrors {@see HubWebhookReceived} and {@see HubWebhookIgnored}: one
 * event per outcome, so a consumer can listen instead of subclassing.
 */
final class HubWebhookHandled
{
    public function __construct(
        public readonly HubWebhookEnvelope $envelope,
        public readonly ?string $eventId,
        public readonly ?string $requestId,
    ) {}
}
