<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Events;

use Emeq\HubSdk\Webhooks\HubWebhookEnvelope;

final class HubWebhookHandled
{
    public function __construct(
        public readonly HubWebhookEnvelope $envelope,
        public readonly ?string $eventId,
        public readonly ?string $requestId,
    ) {}
}
