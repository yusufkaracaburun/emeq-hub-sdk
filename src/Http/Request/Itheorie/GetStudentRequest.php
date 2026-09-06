<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http\Request\Itheorie;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetStudentRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $accessCode,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/itheorie/students/'.rawurlencode($this->accessCode);
    }
}
