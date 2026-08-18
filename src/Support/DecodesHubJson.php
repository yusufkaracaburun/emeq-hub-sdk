<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Support;

use Emeq\HubSdk\Exceptions\HubException;

trait DecodesHubJson
{
    /** @return array<string, mixed> */
    protected function json(mixed $payload): array
    {
        if (! is_array($payload)) {
            throw new HubException(
                'Expected JSON object/array from Hub.',
                error: 'invalid_response',
                category: 'PROVIDER_ERROR',
            );
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /** @return list<array<string, mixed>> */
    protected function jsonList(mixed $payload): array
    {
        if (! is_array($payload)) {
            throw new HubException(
                'Expected JSON list from Hub.',
                error: 'invalid_response',
                category: 'PROVIDER_ERROR',
            );
        }

        if (array_is_list($payload)) {
            /** @var list<array<string, mixed>> $payload */
            return $payload;
        }

        if (isset($payload['data']) && is_array($payload['data']) && array_is_list($payload['data'])) {
            /** @var list<array<string, mixed>> $list */
            $list = $payload['data'];

            return $list;
        }

        throw new HubException(
            'Expected JSON list from Hub.',
            error: 'invalid_response',
            category: 'PROVIDER_ERROR',
        );
    }
}
