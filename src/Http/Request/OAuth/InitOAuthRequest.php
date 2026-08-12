<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http\Request\OAuth;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Provider key is a free string from Hub discovery — no SDK allowlist.
 */
class InitOAuthRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $provider,
        private readonly string $accountExternalId,
        private readonly ?string $returnUrl = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/oauth/'.rawurlencode($this->provider).'/init';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        $body = ['account_external_id' => $this->accountExternalId];

        if ($this->returnUrl !== null) {
            $body['return_url'] = $this->returnUrl;
        }

        return $body;
    }
}
