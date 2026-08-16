<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Tests\Doubles;

use Emeq\HubSdk\Contracts\ResolvesAccountId;

final class FixedAccountId implements ResolvesAccountId
{
    public function __construct(private string $accountId = 'tenant-1') {}

    public function accountId(): string
    {
        return $this->accountId;
    }

    public function displayName(): ?string
    {
        return 'Tenant One';
    }
}
