<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http\Middleware;

use Emeq\HubSdk\Exceptions\HubException;
use Saloon\Contracts\ResponseMiddleware;
use Saloon\Http\Response;

/**
 * Ensures failed Hub responses throw typed HubException subclasses.
 * Saloon already maps via Connector::getRequestException when throw is used;
 * this middleware covers send() without AlwaysThrowOnErrors for clarity.
 */
class MapHubErrors implements ResponseMiddleware
{
    public function __invoke(Response $response): Response
    {
        if ($response->failed()) {
            $body = [];

            try {
                /** @var array<string, mixed>|null $decoded */
                $decoded = $response->json();
                $body = is_array($decoded) ? $decoded : [];
            } catch (\Throwable) {
                $body = ['message' => $response->body()];
            }

            throw HubException::fromEnvelope($body, $response->status());
        }

        return $response;
    }
}
