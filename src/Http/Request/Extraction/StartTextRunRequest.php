<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http\Request\Extraction;

use Emeq\HubSdk\Http\Concerns\HasAccountIdHeader;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class StartTextRunRequest extends Request implements HasBody
{
    use HasAccountIdHeader;
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $profile,
        private readonly int $version,
        private readonly string $text,
        private readonly string $accountId,
        private readonly string $idempotencyKey,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/extraction/runs';
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return [
            ...$this->accountIdHeaders($this->accountId),
            'Idempotency-Key' => $this->idempotencyKey,
        ];
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return [
            'profile' => $this->profile,
            'version' => $this->version,
            'text' => $this->text,
        ];
    }
}
