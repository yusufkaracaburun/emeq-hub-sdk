<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http;

use Emeq\HubSdk\Exceptions\MissingConfigurationException;
use Emeq\HubSdk\Http\Middleware\MapHubErrors;
use Emeq\HubSdk\Support\SdkIdentity;
use Illuminate\Support\Facades\Config;
use Saloon\Contracts\Authenticator;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Http\Response;
use Throwable;

class HubConnector extends Connector
{
    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly ?string $pat = null,
        private readonly ?int $timeoutSeconds = null,
    ) {
        $this->middleware()->onResponse(new MapHubErrors);
    }

    public function resolveBaseUrl(): string
    {
        $base = mb_rtrim($this->baseUrl ?? $this->configuredString('hub.base_url'), '/');

        if ($base === '') {
            throw MissingConfigurationException::missingBaseUrl();
        }

        return $base.'/v1';
    }

    protected function defaultAuth(): ?Authenticator
    {
        $pat = $this->pat ?? $this->configuredString('hub.pat');

        if ($pat === '') {
            throw MissingConfigurationException::missingPat();
        }

        return new TokenAuthenticator($pat);
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => SdkIdentity::userAgent(),
            SdkIdentity::VERSION_HEADER => SdkIdentity::version(),
        ];
    }

    /** @return array<string, mixed> */
    protected function defaultConfig(): array
    {
        return [
            'timeout' => $this->timeoutSeconds ?? $this->configuredTimeout(),
        ];
    }

    public function getRequestException(Response $response, ?Throwable $senderException): ?Throwable
    {
        return HubErrorResponse::toException($response, $senderException);
    }

    private function configuredString(string $key): string
    {
        $value = Config::get($key);

        return is_string($value) ? $value : '';
    }

    private function configuredTimeout(): int
    {
        $value = Config::get('hub.timeout');

        return is_numeric($value) ? (int) $value : 30;
    }
}
