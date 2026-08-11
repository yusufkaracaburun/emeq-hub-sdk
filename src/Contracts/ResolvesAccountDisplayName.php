<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Contracts;

/**
 * Optional: human-readable Hub account display_name on first connect.
 * Bind in the consumer when you want a name other than null.
 */
interface ResolvesAccountDisplayName
{
    public function displayName(): ?string;
}
