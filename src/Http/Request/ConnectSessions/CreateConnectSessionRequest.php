<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http\Request\ConnectSessions;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class CreateConnectSessionRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $accountExternalId,
        private readonly ?string $displayName = null,
        private readonly ?string $returnUrl = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/connect-sessions';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        $body = ['account_external_id' => $this->accountExternalId];

        if ($this->displayName !== null) {
            $body['display_name'] = $this->displayName;
        }

        if ($this->returnUrl !== null) {
            $body['return_url'] = $this->returnUrl;
        }

        return $body;
    }
}
