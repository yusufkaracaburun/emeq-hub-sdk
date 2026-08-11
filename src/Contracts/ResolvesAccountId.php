<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Contracts;

/**
 * Host app binds tenant → Hub account external_id server-side (never from
 * untrusted client input).
 */
interface ResolvesAccountId
{
    public function accountId(): string;
}
