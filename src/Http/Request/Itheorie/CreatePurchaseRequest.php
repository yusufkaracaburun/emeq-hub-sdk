<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http\Request\Itheorie;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class CreatePurchaseRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /** @param  array<string, mixed>  $purchase */
    public function __construct(
        private readonly array $purchase,
        private readonly string $idempotencyKey,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/itheorie/purchases';
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return ['Idempotency-Key' => $this->idempotencyKey];
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return $this->purchase;
    }
}
