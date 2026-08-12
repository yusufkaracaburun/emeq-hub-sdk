<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Resources;

use Emeq\HubSdk\Exceptions\HubException;

/**
 * One page of an accounting collection.
 *
 * Hub answers collection reads with `{data: [...], next_cursor: "…"}`. Returning
 * the bare list would drop the cursor and make pagination impossible; returning
 * the raw envelope would push the shape onto every caller. This carries both,
 * typed.
 */
final class AccountingPage
{
    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function __construct(
        public readonly array $items,
        public readonly ?string $nextCursor = null,
    ) {}

    public static function fromPayload(mixed $payload): self
    {
        if (! is_array($payload)) {
            throw new HubException(
                'Expected a JSON object from Hub.',
                error: 'invalid_response',
                category: 'PROVIDER_ERROR',
            );
        }

        $data = $payload['data'] ?? $payload;

        if (! is_array($data) || ! array_is_list($data)) {
            throw new HubException(
                'Expected a JSON list from Hub.',
                error: 'invalid_response',
                category: 'PROVIDER_ERROR',
            );
        }

        $cursor = $payload['next_cursor'] ?? null;

        /** @var list<array<string, mixed>> $data */
        return new self(
            items: $data,
            nextCursor: is_string($cursor) && $cursor !== '' ? $cursor : null,
        );
    }

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }
}
