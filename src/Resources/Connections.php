<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Resources;

use Emeq\HubSdk\Http\Request\Connections\DeleteConnectionRequest;
use Emeq\HubSdk\Http\Request\Connections\GetConnectionRequest;

class Connections extends Resource
{
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
