<?php

declare(strict_types=1);

use Emeq\HubSdk\Http\HubConnector;
use Emeq\HubSdk\Http\Request\Connections\CreateConnectionRequest;
use Emeq\HubSdk\Hub;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('creates a connection for an account and any provider', function (): void {
    $mock = new MockClient([
        CreateConnectionRequest::class => MockResponse::make(['id' => 7, 'status' => 'active', 'fingerprint' => null], 201),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    $connection = app(Hub::class)->connections()->create(42, 'extraction');

    expect($connection)->toBe(['id' => 7, 'status' => 'active', 'fingerprint' => null]);

    $mock->assertSent(function (CreateConnectionRequest $request): bool {
        return $request->getMethod() === Method::POST
            && $request->resolveEndpoint() === '/connections'
            && $request->body()?->all() === ['account_id' => 42, 'provider' => 'extraction'];
    });
});
