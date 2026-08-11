<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http\Request\Accounts;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class CreateAccountRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $externalId,
        private readonly ?string $displayName = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/accounts';
    }

    protected function defaultBody(): array
    {
        $body = ['external_id' => $this->externalId];

        if ($this->displayName !== null) {
            $body['display_name'] = $this->displayName;
        }

        return $body;
    }
}
