<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Support;

use Emeq\HubSdk\Contracts\ResolvesAccountId;
use InvalidArgumentException;

trait ResolvesAccountContext
{
    use DecodesHubJson;

    protected function resolveAccountId(?string $accountId = null): string
    {
        if ($accountId !== null && $accountId !== '') {
            return $accountId;
        }

        if ($this->accountIdResolver !== null) {
            $resolved = $this->accountIdResolver->accountId();

            if ($resolved !== '') {
                return $resolved;
            }
        }

        throw new InvalidArgumentException(
            'Account id is required. Pass it explicitly or bind '.ResolvesAccountId::class.'.',
        );
    }
}
