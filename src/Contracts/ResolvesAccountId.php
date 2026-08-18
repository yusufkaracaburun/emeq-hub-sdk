<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Contracts;

interface ResolvesAccountId
{
    public function accountId(): string;

    public function displayName(): ?string;
}
