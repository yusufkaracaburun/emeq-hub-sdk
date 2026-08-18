<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Support;

use Emeq\HubSdk\Exceptions\MissingConfigurationException;

trait ResolvesAccountContext
{
    use DecodesHubJson;

    /**
     * @throws MissingConfigurationException when neither an explicit id nor a
     *                                       bound resolver yields one
     */
    protected function resolveAccountId(?string $accountId = null): string
    {
        $resolved = $this->resolveOptionalAccountId($accountId);

        if ($resolved === null) {
            throw MissingConfigurationException::missingAccountId();
        }

        return $resolved;
    }

    protected function resolveOptionalAccountId(?string $accountId = null): ?string
    {
        if ($accountId !== null && $accountId !== '') {
            return $accountId;
        }

        $resolved = $this->accountIdResolver?->accountId() ?? '';

        return $resolved !== '' ? $resolved : null;
    }
}
