<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http\Request\Integrations;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class ListIntegrationsRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly ?string $accountExternalId = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/integrations';
    }

    /** @return array<string, mixed> */
    protected function defaultQuery(): array
    {
        if ($this->accountExternalId === null || $this->accountExternalId === '') {
            return [];
        }

        return ['account_external_id' => $this->accountExternalId];
    }
}
