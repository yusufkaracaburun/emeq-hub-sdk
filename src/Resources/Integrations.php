<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Resources;

use Emeq\HubSdk\Contracts\ResolvesAccountId;
use Emeq\HubSdk\Http\HubConnector;
use Emeq\HubSdk\Http\Request\Integrations\ListIntegrationsRequest;
use Emeq\HubSdk\Support\ResolvesAccountContext;

class Integrations
{
    use ResolvesAccountContext;

    public function __construct(
        private readonly HubConnector $connector,
        private readonly ?ResolvesAccountId $accountIdResolver = null,
    ) {}

    /**
     * Data-driven provider catalog (+ optional per-account status).
     * New Hub partners appear here without an SDK release.
     *
     * The account is optional by design: without one Hub returns the bare
     * catalog, with one it adds per-account connection status.
     *
     * @return list<array<string, mixed>>
     */
    public function list(?string $accountExternalId = null): array
    {
        $accountExternalId = $this->resolveOptionalAccountId($accountExternalId);

        $response = $this->connector->send(new ListIntegrationsRequest($accountExternalId));

        return $this->jsonList($response->json());
    }
}
