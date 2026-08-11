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

    /**
     * @param  array<string, mixed>  $query
     */
    public function __construct(
        private readonly string $path,
        private readonly string $accountId,
        private readonly array $query = [],
    ) {}

    public function resolveEndpoint(): string
    {
        return $this->path;
    }

    protected function defaultHeaders(): array
    {
        return $this->accountIdHeaders($this->accountId);
    }

    protected function defaultQuery(): array
    {
        return $this->query;
    }
}
