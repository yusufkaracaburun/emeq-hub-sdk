<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Resources;

use Emeq\HubSdk\Http\Request\OAuth\InitOAuthRequest;

class OAuth extends Resource
{
    /** @return array<string, mixed> */
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
