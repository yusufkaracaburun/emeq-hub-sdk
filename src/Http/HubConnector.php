<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http;

use Emeq\HubSdk\Exceptions\MissingConfigurationException;
use Emeq\HubSdk\Http\Middleware\MapHubErrors;
use Saloon\Contracts\Authenticator;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Http\Response;
use Throwable;

/**
 * Single Saloon connector for Hub /v1. Provider-agnostic — partner growth
 * happens on the Hub; this client stays on the canonical surface.
 *
 * @internal Prefer Facades\Hub / Resources from app code.
 */
class HubConnector extends Connector
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $pat,
        private readonly int $timeoutSeconds = 30,
    ) {
        $this->middleware()->onResponse(new MapHubErrors);
    }

    public function resolveBaseUrl(): string
    {
        $base = mb_rtrim($this->baseUrl, '/');

        if ($base === '') {
            throw MissingConfigurationException::missingBaseUrl();
        }

        return $base.'/v1';
    }

    protected function defaultAuth(): ?Authenticator
    {
        if ($this->pat === '') {
            throw MissingConfigurationException::missingPat();
        }

        return new TokenAuthenticator($this->pat);
    }

    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    protected function defaultConfig(): array
    {
        return [
            'timeout' => $this->timeoutSeconds,
        ];
    }

    /**
     * Reached only via $response->throw() on the public connector() escape hatch —
     * MapHubErrors throws first on the normal send() path.
     */
    public function getRequestException(Response $response, ?Throwable $senderException): ?Throwable
    {
        return HubErrorResponse::toException($response, $senderException);
    }
}
