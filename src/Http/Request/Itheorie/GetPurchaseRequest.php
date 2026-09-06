<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http\Request\Itheorie;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetPurchaseRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $purchaseId,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/itheorie/purchases/'.rawurlencode($this->purchaseId);
    }
}
