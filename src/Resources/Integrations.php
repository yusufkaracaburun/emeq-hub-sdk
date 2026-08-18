<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Resources;

use Emeq\HubSdk\Http\Request\Integrations\ListIntegrationsRequest;

class Integrations extends Resource
{
    /** @return list<array<string, mixed>> */
    public function list(?string $accountExternalId = null): array
    {
        $accountExternalId = $this->resolveOptionalAccountId($accountExternalId);

        $response = $this->connector->send(new ListIntegrationsRequest($accountExternalId));

        return $this->jsonList($response->json());
    }
}
