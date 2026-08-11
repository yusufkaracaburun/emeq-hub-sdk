<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http\Request\Connections;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class DeleteConnectionRequest extends Request
{
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly string|int $connectionId,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/connections/'.$this->connectionId;
    }
}
