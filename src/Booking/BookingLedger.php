<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Booking;

use Emeq\HubSdk\Contracts\ResolvesAccountId;
use Illuminate\Support\Collection;

/**
 * Reads the ledger for the current account.
 *
 * Exists so callers never have to remember that every ledger read is scoped to
 * an account — an unscoped read shows another administration's bookings.
 */
class BookingLedger
{
    public function __construct(private readonly ResolvesAccountId $account) {}

    public function for(string $externalId): ?HubDocument
    {
        return $this->forExternalIds([$externalId])->get($externalId);
    }

    /**
     * @param  list<string>  $externalIds
     * @return Collection<string, HubDocument>
     */
    public function forExternalIds(array $externalIds): Collection
    {
        if ($externalIds === []) {
            return new Collection;
        }

        return HubDocument::forExternalIds($externalIds, $this->account->accountId());
    }
}
