<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http\Request\Extraction;

use Emeq\HubSdk\Http\Concerns\HasAccountIdHeader;
use Saloon\Contracts\Body\HasBody;
use Saloon\Data\MultipartValue;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasMultipartBody;

class StartFileRunRequest extends Request implements HasBody
{
    use HasAccountIdHeader;
    use HasMultipartBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $profile,
        private readonly int $version,
        private readonly string $contents,
        private readonly string $filename,
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
            'Content-Type' => 'multipart/form-data; boundary='.$this->body()->getBoundary(),
        ];
    }

    /** @return array<int, MultipartValue> */
    protected function defaultBody(): array
    {
        return [
            new MultipartValue('profile', $this->profile),
            new MultipartValue('version', (string) $this->version),
            new MultipartValue('file', $this->contents, $this->filename),
        ];
    }
}
