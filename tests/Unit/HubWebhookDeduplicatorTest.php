<?php

declare(strict_types=1);

use Emeq\HubSdk\Webhooks\HubWebhookDeduplicator;

function deduplicator(): HubWebhookDeduplicator
{
    return new HubWebhookDeduplicator('emeq-hub', 'tenant-1');
}

it('holds the dedupe lock for thirty seconds unless told otherwise', function (): void {
    expect(deduplicator()->lockSeconds())->toBe(30);
});

it('takes the lock window from config', function (): void {
    config()->set('hub.webhook.lock_seconds', 120);

    expect(deduplicator()->lockSeconds())->toBe(120);
});

it('refuses a lock window that would expire immediately', function (): void {
    config()->set('hub.webhook.lock_seconds', 0);

    expect(deduplicator()->lockSeconds())->toBe(1);
});
