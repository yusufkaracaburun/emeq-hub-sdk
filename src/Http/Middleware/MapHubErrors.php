<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http\Middleware;

use Emeq\HubSdk\Http\HubErrorResponse;
use Saloon\Contracts\ResponseMiddleware;
use Saloon\Http\Response;

/**
 * Throws typed HubException subclasses on failed Hub responses.
 *
 * This is the live path: it runs on every response from send(), before Saloon
 * would reach Connector::getRequestException() (which only fires via
 * $response->throw() or the AlwaysThrowOnErrors trait). Both decode through
 * {@see HubErrorResponse}.
 */
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
