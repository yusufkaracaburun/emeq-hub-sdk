<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Resources;

use Emeq\HubSdk\Http\Request\Connections\DeleteConnectionRequest;
use Emeq\HubSdk\Http\Request\Connections\GetConnectionRequest;

/**
 * Connection reads and revocation.
 *
 * **Scoped by PAT, not by account.** Hub resolves `/v1/connections/{id}` against
 * the Consumer behind the token and ignores any account context, so these two
 * methods reach every connection of every account under this consumer. Unlike
 * the rest of the SDK, passing a connection id here is *not* narrowed to the
 * account `ResolvesAccountId` returns.
 *
 * Multi-tenant consumers must therefore verify ownership themselves before
 * handing an id to `get()` / `delete()` — never forward one straight from a
 * request. See the README pitfalls.
 *
 * Pass the `con_…` public id — the value `integrations()->list()`, `oauth()->init()`
 * and the `connection_revoked` webhook all hand back. Hub's own numeric key is
 * accepted too, but it is internal; do not store it.
 */
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
