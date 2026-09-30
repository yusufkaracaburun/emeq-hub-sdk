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

    /**
     * @param  list<array{key: string, label: string, type: 'expense'|'income'}>|null  $categories
     * @param  'manage'|'view'|null  $mode
     * @param  array{name: string, email: string}|null  $actor
     */
    public function __construct(
        private readonly string $accountExternalId,
        private readonly ?string $displayName = null,
        private readonly ?string $returnUrl = null,
        private readonly ?array $categories = null,
        private readonly ?string $mode = null,
        private readonly ?array $actor = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/connect-sessions';
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        $body = ['account_external_id' => $this->accountExternalId];

        if ($this->displayName !== null) {
            $body['display_name'] = $this->displayName;
        }

        if ($this->returnUrl !== null) {
            $body['return_url'] = $this->returnUrl;
        }

        if ($this->categories !== null) {
            $body['categories'] = $this->categories;
        }

        if ($this->mode !== null) {
            $body['mode'] = $this->mode;
        }

        if ($this->actor !== null) {
            $body['actor'] = $this->actor;
        }

        return $body;
    }
}
