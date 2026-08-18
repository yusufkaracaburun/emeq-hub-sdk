<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http\Request\Accounting;

use Emeq\HubSdk\Http\Concerns\HasAccountIdHeader;
use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetAccountingRequest extends Request
{
    use HasAccountIdHeader;

    protected Method $method = Method::GET;

    /** @param  array<string, mixed>  $queryParameters */
    public function __construct(
        private readonly string $path,
        private readonly string $accountId,
        private readonly array $queryParameters = [],
    ) {}

    public function resolveEndpoint(): string
    {
        return $this->path;
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return $this->accountIdHeaders($this->accountId);
    }

    /** @return array<string, mixed> */
    protected function defaultQuery(): array
    {
        return $this->queryParameters;
    }
}
