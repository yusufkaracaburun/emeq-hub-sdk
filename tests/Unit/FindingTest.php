<?php

declare(strict_types=1);

use Emeq\HubSdk\Resources\Finding;

test('reads an explicit blocking flag', function (): void {
    expect(Finding::isBlocking(['blocking' => true]))->toBeTrue()
        ->and(Finding::isBlocking(['blocking' => false]))->toBeFalse();
});

test('reads null, never false, when blocking is absent', function (): void {
    // This is the pre-deploy Hub shape: `severity` only, no `blocking` key at
    // all. Coercing that to `false` would recreate the exact silent trap the
    // field exists to close — a "blocking: false" a caller can trust.
    expect(Finding::isBlocking([
        'code' => 'exact.relation.new',
        'severity' => 'warning',
        'message' => 'De boeking wordt hierop geweigerd.',
    ]))->toBeNull();
});

test('reads null for a non-boolean blocking value', function (): void {
    expect(Finding::isBlocking(['blocking' => 'true']))->toBeNull();
});
