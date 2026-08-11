<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http;

use Emeq\HubSdk\Exceptions\HubException;
use Emeq\HubSdk\Exceptions\MissingConfigurationException;
use Emeq\HubSdk\Http\Middleware\MapHubErrors;
use Saloon\Contracts\Authenticator;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;
use Throwable;

/**
 * Single Saloon connector for Hub /v1. Provider-agnostic — partner growth
 * happens on the Hub; this client stays on the canonical surface.
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

    public function getRequestException(Response $response, ?Throwable $senderException): ?Throwable
    {
        $body = [];

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = $response->json() ?? [];
            $body = is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            $body = ['message' => $response->body()];
        }

        return HubException::fromEnvelope($body, $response->status(), $senderException);
    }

    public function boot(PendingRequest $pendingRequest): void
    {
        // no-op — account headers are set per request
    }
}
