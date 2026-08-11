<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http\Request\Accounting;

use Emeq\HubSdk\Http\Concerns\HasAccountIdHeader;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class SyncAccountingRequest extends Request implements HasBody
{
    use HasAccountIdHeader;
    use HasJsonBody;

    protected Method $method = Method::POST;

    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        private readonly string $accountId,
        private readonly array $body = [],
    ) {}

    public function resolveEndpoint(): string
    {
        return '/accounting/sync';
    }

    protected function defaultHeaders(): array
    {
        return $this->accountIdHeaders($this->accountId);
    }

    protected function defaultBody(): array
    {
        return $this->body;
    }
}
