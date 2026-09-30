<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Resources;

use Emeq\HubSdk\Http\Request\ConnectSessions\CreateConnectSessionRequest;

class ConnectSessions extends Resource
{
    /**
     * @param  list<array{key: string, label: string, type: 'expense'|'income'}>|null  $categories
     * @param  'manage'|'view'|null  $mode
     * @param  array{name: string, email: string}|null  $actor
     * @return array<string, mixed>
     */
    public function create(
        ?string $accountExternalId = null,
        ?string $displayName = null,
        ?string $returnUrl = null,
        ?array $categories = null,
        ?string $mode = null,
        ?array $actor = null,
    ): array {
        $accountExternalId = $this->resolveAccountId($accountExternalId);

        $response = $this->connector->send(new CreateConnectSessionRequest(
            accountExternalId: $accountExternalId,
            displayName: $displayName,
            returnUrl: $returnUrl,
            categories: $categories,
            mode: $mode,
            actor: $actor,
        ));

        return $this->json($response->json());
    }
}
