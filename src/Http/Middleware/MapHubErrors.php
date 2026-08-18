<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http\Middleware;

use Emeq\HubSdk\Http\HubErrorResponse;
use Saloon\Contracts\ResponseMiddleware;
use Saloon\Http\Response;

class MapHubErrors implements ResponseMiddleware
{
    public function __invoke(Response $response): Response
    {
        if ($response->failed()) {
            throw HubErrorResponse::toException($response);
        }

        return $response;
    }
}
