<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http\Request\Accounting;

use Emeq\HubSdk\Http\Concerns\HasAccountIdHeader;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class PutMappingRequest extends Request implements HasBody
{
    use HasAccountIdHeader;
    use HasJsonBody;

    protected Method $method = Method::PUT;

    /**
     * @param  array<string, mixed>  $mapping
     */
    public function __construct(
        private readonly array $mapping,
        private readonly string $accountId,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/accounting/mapping';
    }

    protected function defaultHeaders(): array
    {
        return $this->accountIdHeaders($this->accountId);
    }

    protected function defaultBody(): array
    {
        return $this->mapping;
    }
}
