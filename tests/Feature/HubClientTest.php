<?php

declare(strict_types=1);

use Emeq\HubSdk\Exceptions\AuthenticationException;
use Emeq\HubSdk\Exceptions\NotFoundException;
use Emeq\HubSdk\Exceptions\ValidationException;
use Emeq\HubSdk\Hub;
use Emeq\HubSdk\Http\HubConnector;
use Emeq\HubSdk\Http\Request\Integrations\ListIntegrationsRequest;
use Emeq\HubSdk\Http\Request\OAuth\InitOAuthRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

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
