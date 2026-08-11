<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http\Concerns;

trait HasAccountIdHeader
{
    protected function accountIdHeaders(?string $accountId): array
    {
        if ($accountId === null || $accountId === '') {
            return [];
        }

        return ['X-Account-Id' => $accountId];
    }
}
