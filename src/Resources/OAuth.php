<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Resources;

use Emeq\HubSdk\Contracts\ResolvesAccountId;
use Emeq\HubSdk\Http\HubConnector;
use Emeq\HubSdk\Http\Request\OAuth\InitOAuthRequest;
use Emeq\HubSdk\Support\ResolvesAccountContext;

class OAuth
{
    use ResolvesAccountContext;

    public function __construct(
        private readonly HubConnector $connector,
        private readonly ?ResolvesAccountId $accountIdResolver = null,
    ) {}

    /**
     * Start OAuth for any Hub provider key (no SDK allowlist).
     *
     * @return array{connection_id: mixed, redirect_url: mixed, ...}
     */
    public function init(string $provider, ?string $accountExternalId = null, ?string $returnUrl = null): array
    {
        $accountExternalId = $this->resolveAccountId($accountExternalId);

        $response = $this->connector->send(new InitOAuthRequest(
            provider: $provider,
            accountExternalId: $accountExternalId,
            returnUrl: $returnUrl,
        ));

        return $this->json($response->json());
    }
}
