<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http\Request\Extraction;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class PutProfileVersionRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::PUT;

    /** @param  array<string, mixed>  $schema */
    public function __construct(
        private readonly string $key,
        private readonly int $version,
        private readonly array $schema,
        private readonly ?string $instructions = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/extraction/profiles/'.rawurlencode($this->key).'/versions/'.$this->version;
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        $body = ['schema' => $this->schema];

        if ($this->instructions !== null) {
            $body['instructions'] = $this->instructions;
        }

        return $body;
    }
}
