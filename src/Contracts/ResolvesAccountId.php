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

    /**
     * Human-readable Hub account name, used on first connect. Return null to
     * let Hub name the account itself.
     */
    public function displayName(): ?string;
}
