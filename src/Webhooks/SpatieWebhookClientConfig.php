<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Webhooks;

use Spatie\WebhookClient\Models\WebhookCall;
use Spatie\WebhookClient\SignatureValidator\DefaultSignatureValidator;
use Spatie\WebhookClient\WebhookResponse\DefaultRespondsTo;

/**
 * Build a Spatie webhook-client config entry for Hub inbound webhooks.
 *
 * Pure: reads no global state, so it can be called before the container is
 * booted. HubServiceProvider owns the config lookups.
 */
final class SpatieWebhookClientConfig
{
    /**
     * @return array<string, mixed>
     */
    public static function make(
        string $signingSecret,
        string $profileClass = HubWebhookProfile::class,
        string $jobClass = ProcessHubWebhookJob::class,
        string $name = 'emeq-hub',
    ): array {
        return [
            'name' => $name,
            'signing_secret' => $signingSecret,
            'signature_header_name' => HubWebhookHeaders::SIGNATURE,
            'signature_validator' => DefaultSignatureValidator::class,
            'webhook_profile' => $profileClass,
            'webhook_response' => DefaultRespondsTo::class,
            'webhook_model' => WebhookCall::class,
            'store_headers' => HubWebhookHeaders::storeHeaders(),
            'store_attachments' => false,
            'process_webhook_job' => $jobClass,
        ];
    }
}
