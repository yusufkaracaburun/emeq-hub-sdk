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
     * Note: not named `$body` — HasJsonBody already declares a non-readonly
     * `$body` property, and promoting a readonly one of the same name is a
     * fatal error at class-load time. Same trap as `$query` in
     * GetAccountingRequest.
     *
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly string $accountId,
        private readonly array $payload = [],
    ) {}

    public function resolveEndpoint(): string
    {
        return '/accounting/sync';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return $this->accountIdHeaders($this->accountId);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return $this->payload;
    }
}
