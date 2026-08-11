<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Resources;

use Emeq\HubSdk\Contracts\ResolvesAccountId;
use Emeq\HubSdk\Http\HubConnector;
use Emeq\HubSdk\Http\Request\Integrations\ListIntegrationsRequest;
use Emeq\HubSdk\Support\DecodesHubJson;

class Integrations
{
    use DecodesHubJson;

    public function __construct(
        private readonly HubConnector $connector,
        private readonly ?ResolvesAccountId $accountIdResolver = null,
    ) {}

    /**
     * Data-driven provider catalog (+ optional per-account status).
     * New Hub partners appear here without an SDK release.
     *
     * @return list<array<string, mixed>>
     */
    public function list(?string $accountExternalId = null): array
    {
        $accountExternalId ??= $this->accountIdResolver?->accountId();

        $response = $this->connector->send(new ListIntegrationsRequest($accountExternalId));

        return $this->jsonList($response->json());
    }
}
