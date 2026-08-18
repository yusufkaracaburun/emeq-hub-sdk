<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Contracts;

interface ResolvesWebhookAccount
{
    public function prepare(string $accountId): bool;
}
