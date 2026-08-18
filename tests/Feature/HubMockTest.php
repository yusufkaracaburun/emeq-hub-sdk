<?php

declare(strict_types=1);

use Emeq\HubSdk\Exceptions\AuthenticationException;
use Emeq\HubSdk\Exceptions\HubException;
use Emeq\HubSdk\Exceptions\NotFoundException;
use Emeq\HubSdk\Exceptions\ValidationException;
use Emeq\HubSdk\Hub;
use Emeq\HubSdk\Testing\HubMock;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

// No ResolvesAccountId is bound in the package test app, so every accounting
// call passes its account explicitly.
const ACCOUNT = 'tenant-1';

afterEach(function (): void {
    MockClient::destroyGlobal();
});

/**
 * @return array<string, mixed>
 */
function mockedBody(MockResponse $response): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) $response->body(), true, flags: JSON_THROW_ON_ERROR);

    return $decoded;
}

it('answers every accounting read from the captured fixtures', function (): void {
    MockClient::global(HubMock::accounting());

    $accounting = app(Hub::class)->accounting();

    expect($accounting->capabilities(ACCOUNT))->toBe(HubMock::fixture('capabilities'))
        ->and($accounting->referenceData(ACCOUNT))->toBe(HubMock::fixture('reference-data'))
        ->and($accounting->mapping(ACCOUNT))->toBe(HubMock::fixture('mapping'));

    expect($accounting->documents(['type' => 'sales_invoice'], ACCOUNT)->items)
        ->toBe(HubMock::fixture('documents')['data']);
});

it('serves collection reads as pages that keep their cursor', function (): void {
    MockClient::global(HubMock::accounting());

    $accounting = app(Hub::class)->accounting();

    // Captured against a provider with more ledger accounts than one page.
    $ledger = $accounting->ledgerAccounts([], ACCOUNT);
    expect($ledger->hasMore())->toBeTrue()
        ->and($ledger->nextCursor)->toBe(HubMock::fixture('ledger-accounts')['next_cursor']);

    // Captured on an administration whose relations fit in a single page.
    expect($accounting->customers([], ACCOUNT)->hasMore())->toBeFalse()
        ->and($accounting->suppliers([], ACCOUNT)->items)->toBe(HubMock::fixture('suppliers')['data'])
        ->and($accounting->bankStatements([], ACCOUNT)->items)->toBe([]);
});

it('distinguishes a clean validation from one with findings', function (): void {
    MockClient::global(HubMock::accounting());

    $clean = app(Hub::class)->accounting()->validateDocument(['type' => 'sales_invoice'], ACCOUNT);

    // A clean document still carries findings — Hub reports the matched
    // relation as `info`. Consumers must read `valid`, not `findings === []`.
    expect($clean['valid'])->toBeTrue()
        ->and($clean['findings'])->not->toBe([])
        ->and($clean['summary']['errors'])->toBe(0);

    $failing = mockedBody(HubMock::validateDocument(valid: false));

    expect($failing['valid'])->toBeFalse()
        ->and($failing['summary']['errors'])->toBeGreaterThan(0)
        ->and(array_column($failing['findings'], 'severity'))->toContain('error');
});

it('books a document and replays the same key onto the same booking', function (): void {
    MockClient::global(['*/v1/accounting/documents' => HubMock::createDocument()]);

    $document = ['type' => 'sales_invoice', 'external_id' => 'invoice-1'];

    $booked = app(Hub::class)->accounting()->createDocument(
        $document,
        idempotencyKey: $document['external_id'],
        accountId: ACCOUNT,
    );

    // Captured live: a retry carrying the same key answers with the same
    // provider reference, so the document is booked once.
    $replayed = app(Hub::class)->accounting()->createDocument(
        $document,
        idempotencyKey: $document['external_id'],
        accountId: ACCOUNT,
    );

    expect($booked['status'])->toBe('posted')
        ->and($booked['external_ref'])->not->toBeNull()
        ->and($replayed)->toBe($booked);
});

it('answers a booking with the list read when the map has no room for both', function (): void {
    // Saloon keys mocks on the URL alone, and POST /accounting/documents shares
    // its URL with the list read. accounting() answers the read, so a test that
    // books has to key that URL itself — this pins the trap so the docblock
    // saying so cannot quietly stop being true.
    MockClient::global(HubMock::accounting());

    $booked = app(Hub::class)->accounting()->createDocument(
        ['type' => 'sales_invoice', 'external_id' => 'invoice-1'],
        idempotencyKey: 'invoice-1',
        accountId: ACCOUNT,
    );

    expect($booked)->toBe(HubMock::fixture('documents'))
        ->and($booked)->not->toBe(HubMock::fixture('create-document'));
});

it('reports what a re-sync pulled', function (): void {
    MockClient::global(HubMock::accounting());

    expect(app(Hub::class)->accounting()->sync([], ACCOUNT))
        ->toBe(HubMock::fixture('sync'));
});

it('maps the captured error envelopes to the documented exceptions', function (): void {
    $cases = [
        [HubMock::unauthenticated(), AuthenticationException::class],
        [HubMock::notFound(), NotFoundException::class],
        // 400 + VALIDATION_ERROR: the answer to a collection read without its
        // required filter. Category decides here, not the status.
        [HubMock::invalidQuery(), ValidationException::class],
    ];

    foreach ($cases as [$response, $expected]) {
        MockClient::destroyGlobal();
        MockClient::global(['*/v1/accounting/capabilities' => $response]);

        expect(fn () => app(Hub::class)->accounting()->capabilities(ACCOUNT))->toThrow($expected);
    }
});

it('serves each factory from its own fixture, with the captured status', function (): void {
    // Comparing a factory's body to the fixture it loads would be circular. What
    // can actually break: a factory pointing at the wrong file, two factories
    // sharing one, or a status that drifts from what Hub really answered.
    $factories = [
        'capabilities' => [HubMock::capabilities(), 200],
        'reference-data' => [HubMock::referenceData(), 200],
        'mapping' => [HubMock::mapping(), 200],
        'documents' => [HubMock::documents(), 200],
        'ledger-accounts' => [HubMock::ledgerAccounts(), 200],
        'tax-codes' => [HubMock::taxCodes(), 200],
        'customers' => [HubMock::customers(), 200],
        'suppliers' => [HubMock::suppliers(), 200],
        'bank-statements-empty' => [HubMock::bankStatements(), 200],
        'create-document' => [HubMock::createDocument(), 201],
        'create-document-with-warnings' => [HubMock::createDocumentWithWarnings(), 201],
        'sync' => [HubMock::sync(), 200],
        'validate-clean' => [HubMock::validateDocument(), 200],
        'validate-findings' => [HubMock::validateDocument(valid: false), 200],
        'error-unauthenticated' => [HubMock::unauthenticated(), 401],
        'error-not-found' => [HubMock::notFound(), 404],
        'error-invalid-query' => [HubMock::invalidQuery(), 400],
    ];

    $served = [];

    foreach ($factories as $fixture => [$response, $status]) {
        expect(mockedBody($response))->toBe(HubMock::fixture($fixture))
            ->and($response->status())->toBe($status);

        $served[] = $fixture;
    }

    // Every shipped file is reachable, so a renamed or abandoned fixture shows
    // up here instead of rotting unused next to the ones that are live.
    $shipped = array_map(
        fn (string $path): string => basename($path, '.json'),
        (array) glob(dirname((new ReflectionClass(HubMock::class))->getFileName()).'/fixtures/*.json'),
    );

    sort($shipped);
    sort($served);

    expect($shipped)->toBe($served);
});

it('refuses a fixture name it does not ship', function (): void {
    expect(fn () => HubMock::fixture('not-a-fixture'))
        ->toThrow(HubException::class);
});
