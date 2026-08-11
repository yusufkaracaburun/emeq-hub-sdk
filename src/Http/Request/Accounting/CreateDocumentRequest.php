<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http\Request\Accounting;

use Emeq\HubSdk\Http\Concerns\HasAccountIdHeader;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class CreateDocumentRequest extends Request implements HasBody
{
    use HasAccountIdHeader;
    use HasJsonBody;

    protected Method $method = Method::POST;

    /**
     * @param  array<string, mixed>  $document
     */
    public function __construct(
        private readonly array $document,
        private readonly string $accountId,
        private readonly string $idempotencyKey,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/accounting/documents';
    }

    protected function defaultHeaders(): array
    {
        return [
            ...$this->accountIdHeaders($this->accountId),
            'Idempotency-Key' => $this->idempotencyKey,
        ];
    }

    protected function defaultBody(): array
    {
        return $this->document;
    }
}
