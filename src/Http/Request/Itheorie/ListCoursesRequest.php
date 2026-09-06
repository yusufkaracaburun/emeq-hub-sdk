<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Http\Request\Itheorie;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class ListCoursesRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly ?int $page = null,
        private readonly ?int $limit = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/itheorie/courses';
    }

    /** @return array<string, mixed> */
    protected function defaultQuery(): array
    {
        $query = [];

        if ($this->page !== null) {
            $query['page'] = $this->page;
        }

        if ($this->limit !== null) {
            $query['limit'] = $this->limit;
        }

        return $query;
    }
}
