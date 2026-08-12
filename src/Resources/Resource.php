<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Resources;

use Emeq\HubSdk\Contracts\ResolvesAccountId;
use Emeq\HubSdk\Http\HubConnector;
use Emeq\HubSdk\Support\ResolvesAccountContext;

/**
 * A Hub resource is a connector plus an optional account resolver.
 *
 * Subclasses add endpoint methods only; account resolution and JSON decoding
 * come from {@see ResolvesAccountContext}.
 */
abstract class Resource
{
    use ResolvesAccountContext;

    public function __construct(
        protected readonly HubConnector $connector,
        protected readonly ?ResolvesAccountId $accountIdResolver = null,
    ) {}
}
