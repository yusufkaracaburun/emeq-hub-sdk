<?php

declare(strict_types=1);

use Emeq\HubSdk\Exceptions\AuthenticationException;
use Emeq\HubSdk\Exceptions\HubException;
use Emeq\HubSdk\Exceptions\MissingConfigurationException;
use Emeq\HubSdk\Exceptions\NotFoundException;
use Emeq\HubSdk\Exceptions\ValidationException;
use Emeq\HubSdk\Http\HubConnector;
use Emeq\HubSdk\Http\Request\Accounting\GetAccountingRequest;
use Emeq\HubSdk\Http\Request\Integrations\ListIntegrationsRequest;
use Emeq\HubSdk\Http\Request\OAuth\InitOAuthRequest;
use Emeq\HubSdk\Hub;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

// MockClient::global() is `??=`: a leaked instance silently ignores the next
// test's mock data. Same reason consumers must destroy it in their own suites.
afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('lists integrations without hardcoding providers', function (): void {
    $mock = new MockClient([
        ListIntegrationsRequest::class => MockResponse::make([
            [
                'key' => 'exact',
                'label' => 'Exact Online',
                'connectable' => true,
                'status' => 'disconnected',
            ],
            [
                'key' => 'moneybird',
                'label' => 'Moneybird',
                'connectable' => true,
                'status' => 'disconnected',
            ],
        ], 200),
    ]);

    $connector = app(HubConnector::class);
    $connector->withMockClient($mock);

    $list = app(Hub::class)->integrations()->list('tenant-1');

    expect($list)->toHaveCount(2)
        ->and($list[1]['key'])->toBe('moneybird');

    $mock->assertSent(function (ListIntegrationsRequest $request): bool {
        return $request->query()->all() === ['account_external_id' => 'tenant-1'];
    });
});

it('accepts any provider string on oauth init (no SDK allowlist)', function (): void {
    $mock = new MockClient([
        InitOAuthRequest::class => MockResponse::make([
            'connection_id' => '99',
            'redirect_url' => 'https://partner.example/consent',
        ], 200),
    ]);

    $connector = app(HubConnector::class);
    $connector->withMockClient($mock);

    $result = app(Hub::class)->oauth()->init('future-partner', accountExternalId: 'tenant-1');

    expect($result['redirect_url'])->toBe('https://partner.example/consent');

    $mock->assertSent(function (InitOAuthRequest $request): bool {
        return $request->resolveEndpoint() === '/oauth/future-partner/init';
    });
});

it('maps hub error envelope to typed exceptions', function (): void {
    $mock = new MockClient([
        ListIntegrationsRequest::class => MockResponse::make([
            'error' => 'unauthenticated',
            'category' => 'AUTHENTICATION_ERROR',
            'message' => 'Missing token',
            'request_id' => 'req_1',
        ], 401),
    ]);

    $connector = app(HubConnector::class);
    $connector->withMockClient($mock);

    try {
        app(Hub::class)->integrations()->list();
        $this->fail('Expected AuthenticationException');
    } catch (AuthenticationException $e) {
        expect($e->error)->toBe('unauthenticated')
            ->and($e->requestId)->toBe('req_1')
            ->and($e->status)->toBe(401);
    }
});

it('maps validation errors', function (): void {
    $mock = new MockClient([
        InitOAuthRequest::class => MockResponse::make([
            'error' => 'validation_error',
            'category' => 'VALIDATION_ERROR',
            'message' => 'The external id field is required.',
            'request_id' => 'req_2',
        ], 422),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    expect(fn () => app(Hub::class)->oauth()->init('exact', 'tenant-1'))
        ->toThrow(ValidationException::class);
});

it('sends accounting list requests with account header and query', function (): void {
    // Regression: GetAccountingRequest promoted a readonly $query property,
    // which collides with Saloon\Http\Request::$query and fatals at class-load —
    // every accounting GET was unusable.
    $mock = new MockClient([
        GetAccountingRequest::class => MockResponse::make([
            ['id' => 'doc-1'],
        ], 200),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    $page = app(Hub::class)->accounting()->documents(['type' => 'sales_invoice'], 'tenant-1');

    expect($page->items)->toHaveCount(1);

    $mock->assertSent(function (GetAccountingRequest $request): bool {
        return $request->resolveEndpoint() === '/accounting/documents'
            && $request->query()->all() === ['type' => 'sales_invoice'];
    });
});

it('keeps the cursor from a paginated accounting collection', function (): void {
    // Hub answers collection reads with {data, next_cursor}; dropping the cursor
    // would make paging impossible.
    $mock = new MockClient([
        GetAccountingRequest::class => MockResponse::make([
            'data' => [['id' => 'doc-1'], ['id' => 'doc-2']],
            'next_cursor' => 'eyJvIjoyfQ==',
        ], 200),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    $page = app(Hub::class)->accounting()->documents(['type' => 'sales_invoice'], 'tenant-1');

    expect($page->items)->toBe([['id' => 'doc-1'], ['id' => 'doc-2']])
        ->and($page->nextCursor)->toBe('eyJvIjoyfQ==')
        ->and($page->hasMore())->toBeTrue();
});

it('reports the last page as having no cursor', function (): void {
    $mock = new MockClient([
        GetAccountingRequest::class => MockResponse::make([
            'data' => [['id' => 'doc-9']],
            'next_cursor' => null,
        ], 200),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    $page = app(Hub::class)->accounting()->taxCodes([], 'tenant-1');

    expect($page->nextCursor)->toBeNull()
        ->and($page->hasMore())->toBeFalse();
});

it('throws a catchable HubException when no account id can be resolved', function (): void {
    // No ResolvesAccountId bound and no explicit id: consumers who wrap SDK
    // calls in catch (HubException) must still catch this.
    try {
        app(Hub::class)->accounting()->documents();
        $this->fail('Expected MissingConfigurationException');
    } catch (HubException $e) {
        expect($e)->toBeInstanceOf(MissingConfigurationException::class)
            ->and($e->error)->toBe('missing_account_id')
            ->and($e->category)->toBe('CONFIGURATION_ERROR');
    }
});

it('lists the integrations catalog without an account id', function (): void {
    $mock = new MockClient([
        ListIntegrationsRequest::class => MockResponse::make([
            ['key' => 'exact', 'label' => 'Exact Online', 'connectable' => true],
        ], 200),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    expect(app(Hub::class)->integrations()->list())->toHaveCount(1);

    $mock->assertSent(function (ListIntegrationsRequest $request): bool {
        return $request->query()->all() === [];
    });
});

it('is mockable from a consumer app through a global mock client on url patterns', function (): void {
    // The path README documents for consumers: no saloonphp/laravel-plugin, so
    // no Saloon::fake(); and no imports from the package-internal Http\Request
    // namespace, so the mock is keyed on the URL.
    $mock = MockClient::global([
        '*/v1/accounting/documents' => MockResponse::make(['id' => 'doc_1'], 201),
    ]);

    $document = ['type' => 'sales_invoice', 'external_id' => 'invoice-42'];

    $created = app(Hub::class)->accounting()->createDocument(
        $document,
        idempotencyKey: $document['external_id'],
        accountId: 'tenant-1',
    );

    expect($created)->toBe(['id' => 'doc_1']);

    // external_id is the documented source of the key: same document, same key.
    expect($mock->getLastPendingRequest()?->headers()->get('Idempotency-Key'))
        ->toBe('invoice-42');
});

it('maps unknown provider as not found when hub returns 404', function (): void {
    $mock = new MockClient([
        InitOAuthRequest::class => MockResponse::make([
            'error' => 'unknown_provider',
            'category' => 'NOT_FOUND',
            'message' => 'Unknown provider',
            'request_id' => 'req_3',
        ], 404),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    expect(fn () => app(Hub::class)->oauth()->init('nope', 'tenant-1'))
        ->toThrow(NotFoundException::class);
});
