<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Resources;

use Emeq\HubSdk\Contracts\ResolvesAccountId;
use Emeq\HubSdk\Http\HubConnector;
use Emeq\HubSdk\Http\Request\ConnectSessions\CreateConnectSessionRequest;
use Emeq\HubSdk\Support\ResolvesAccountContext;

class ConnectSessions
{
    use ResolvesAccountContext;

    public function __construct(
        private readonly HubConnector $connector,
        private readonly ?ResolvesAccountId $accountIdResolver = null,
    ) {}

    /**
     * Mint Hub's hosted connect handoff URL (`/connect/{account}`).
     *
     * @return array{url: mixed, expires_at: mixed, ...}
     */
    public function create(
        ?string $accountExternalId = null,
        ?string $displayName = null,
        ?string $returnUrl = null,
    ): array {
        $accountExternalId = $this->resolveAccountId($accountExternalId);

        $response = $this->connector->send(new CreateConnectSessionRequest(
            accountExternalId: $accountExternalId,
            displayName: $displayName,
            returnUrl: $returnUrl,
        ));

        return $this->json($response->json());
    }
}
