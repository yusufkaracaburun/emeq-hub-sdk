<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Resources;

use Emeq\HubSdk\Http\Request\Accounts\CreateAccountRequest;

class Accounts extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function create(string $externalId, ?string $displayName = null): array
    {
        $response = $this->connector->send(new CreateAccountRequest($externalId, $displayName));

        return $this->json($response->json());
    }
}
