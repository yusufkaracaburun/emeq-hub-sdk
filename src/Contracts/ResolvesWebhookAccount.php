<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Contracts;

/**
 * Map Hub webhook `account_id` (consumer external_id) to a usable tenant
 * context. Return false to skip store (Spatie still responds 200).
 *
 * Implementations may switch DB / bind tenant as a side effect when returning
 * true — required for multi-DB apps that store webhook_calls per tenant.
 */
interface ResolvesWebhookAccount
{
    public function prepare(string $accountId): bool;
}
