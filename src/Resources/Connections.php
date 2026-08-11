<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Resources;

use Emeq\HubSdk\Http\HubConnector;
use Emeq\HubSdk\Http\Request\Connections\DeleteConnectionRequest;
use Emeq\HubSdk\Http\Request\Connections\GetConnectionRequest;
use Emeq\HubSdk\Support\DecodesHubJson;

class Connections
{
    use DecodesHubJson;

    public function __construct(
        private readonly HubConnector $connector,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function get(string|int $connectionId): array
    {
        $response = $this->connector->send(new GetConnectionRequest($connectionId));

        return $this->json($response->json());
    }

    public function delete(string|int $connectionId): void
    {
        $this->connector->send(new DeleteConnectionRequest($connectionId));
    }
}
