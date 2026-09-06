<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http\Request\Itheorie;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetCourseRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $courseId,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/itheorie/courses/'.rawurlencode($this->courseId);
    }
}
