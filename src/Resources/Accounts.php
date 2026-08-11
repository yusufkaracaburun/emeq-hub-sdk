<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Resources;

use Emeq\HubSdk\Http\HubConnector;
use Emeq\HubSdk\Http\Request\Accounts\CreateAccountRequest;
use Emeq\HubSdk\Support\DecodesHubJson;

class Accounts
{
    use DecodesHubJson;

    public function __construct(
        private readonly HubConnector $connector,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function create(string $externalId, ?string $displayName = null): array
    {
        $response = $this->connector->send(new CreateAccountRequest($externalId, $displayName));

        return $this->json($response->json());
    }
}
