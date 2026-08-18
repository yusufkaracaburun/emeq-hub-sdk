<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Resources;

use Emeq\HubSdk\Contracts\ResolvesAccountId;
use Emeq\HubSdk\Http\HubConnector;
use Emeq\HubSdk\Support\ResolvesAccountContext;

abstract class Resource
{
    use ResolvesAccountContext;

    public function __construct(
        protected readonly HubConnector $connector,
        protected readonly ?ResolvesAccountId $accountIdResolver = null,
    ) {}
}
