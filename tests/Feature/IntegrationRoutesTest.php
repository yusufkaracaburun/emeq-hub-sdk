<?php

declare(strict_types=1);

use Emeq\HubSdk\Contracts\ResolvesAccountDisplayName;
use Emeq\HubSdk\Contracts\ResolvesAccountId;
use Emeq\HubSdk\Http\HubConnector;
use Emeq\HubSdk\Http\Request\Accounts\CreateAccountRequest;
use Emeq\HubSdk\Http\Request\Connections\DeleteConnectionRequest;
use Emeq\HubSdk\Http\Request\Integrations\ListIntegrationsRequest;
use Emeq\HubSdk\Http\Request\OAuth\InitOAuthRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;

describe('with account resolver', function (): void {
    beforeEach(function (): void {
        $this->app->bind(ResolvesAccountId::class, fn (): ResolvesAccountId => new class implements ResolvesAccountId
        {
            public function accountId(): string
            {
                return 'tenant-77';
            }
        });

        $this->app->bind(ResolvesAccountDisplayName::class, fn (): ResolvesAccountDisplayName => new class implements ResolvesAccountDisplayName
        {
            public function displayName(): ?string
            {
                return 'Demo BV';
            }
        });
    });

    it('lists integrations ignoring spoofed X-Account-Id', function (): void {
        $mock = new MockClient([
            ListIntegrationsRequest::class => MockResponse::make([
                [
                    'key' => 'exact',
                    'label' => 'Exact Online',
                    'connectable' => true,
                    'status' => 'disconnected',
                ],
            ], 200),
        ]);
        app(HubConnector::class)->withMockClient($mock);

        $response = $this
            ->withHeader('X-Account-Id', 'spoofed-tenant')
            ->getJson('/api/integrations?account_external_id=spoofed-tenant');

        $response->assertOk()->assertJsonPath('0.key', 'exact');

        $mock->assertSent(function (Request $request): bool {
            if (! $request instanceof ListIntegrationsRequest) {
                return false;
            }

            return ($request->query()->all()['account_external_id'] ?? null) === 'tenant-77';
        });
    });

    it('connect treats account 409 as exists and builds return_url server-side', function (): void {
        $mock = new MockClient([
            CreateAccountRequest::class => MockResponse::make([
                'error' => 'account_exists',
                'message' => 'Account already exists',
            ], 409),
            InitOAuthRequest::class => MockResponse::make([
                'connection_id' => '42',
                'redirect_url' => 'https://partner.example/consent',
            ], 200),
        ]);
        app(HubConnector::class)->withMockClient($mock);

        $response = $this
            ->withHeader('X-Account-Id', 'spoofed-tenant')
            ->postJson('/api/integrations/future-partner/connect', [
                'return_url' => 'https://evil.example/phish',
                'account_external_id' => 'spoofed-tenant',
            ]);

        $response->assertOk()
            ->assertJsonPath('connection_id', '42')
            ->assertJsonPath('redirect_url', 'https://partner.example/consent');

        $mock->assertSent(function (Request $request): bool {
            if (! $request instanceof InitOAuthRequest) {
                return false;
            }

            $body = $request->body()->all();
            $returnUrl = (string) ($body['return_url'] ?? '');

            return ($body['account_external_id'] ?? null) === 'tenant-77'
                && str_ends_with($returnUrl, '/integrations/oauth-callback')
                && ! str_contains($returnUrl, 'evil.example');
        });
    });

    it('connect omits return_url when return_path is empty', function (): void {
        config(['hub.oauth.return_path' => '']);

        $mock = new MockClient([
            CreateAccountRequest::class => MockResponse::make(['id' => 1], 201),
            InitOAuthRequest::class => MockResponse::make([
                'connection_id' => '7',
                'redirect_url' => 'https://partner.example/consent',
            ], 200),
        ]);
        app(HubConnector::class)->withMockClient($mock);

        $this->postJson('/api/integrations/exact/connect')->assertOk();

        $mock->assertSent(function (Request $request): bool {
            if (! $request instanceof InitOAuthRequest) {
                return false;
            }

            return ! array_key_exists('return_url', $request->body()->all());
        });
    });

    it('connect rejects absolute return_path from config', function (): void {
        config(['hub.oauth.return_path' => 'https://evil.example/phish']);

        $response = $this->postJson('/api/integrations/exact/connect');

        $response->assertStatus(422)
            ->assertJsonPath('error', 'invalid_return_path');
    });

    it('destroys an owned connection', function (): void {
        $mock = new MockClient([
            ListIntegrationsRequest::class => MockResponse::make([
                [
                    'key' => 'exact',
                    'connection_id' => '99',
                    'status' => 'connected',
                ],
            ], 200),
            DeleteConnectionRequest::class => MockResponse::make('', 204),
        ]);
        app(HubConnector::class)->withMockClient($mock);

        $this->deleteJson('/api/integrations/99')->assertNoContent();

        $mock->assertSent(DeleteConnectionRequest::class);
    });

    it('refuses to destroy a connection not owned by the account', function (): void {
        $mock = new MockClient([
            ListIntegrationsRequest::class => MockResponse::make([
                [
                    'key' => 'exact',
                    'connection_id' => '99',
                    'status' => 'connected',
                ],
            ], 200),
        ]);
        app(HubConnector::class)->withMockClient($mock);

        $this->deleteJson('/api/integrations/other-id')
            ->assertNotFound()
            ->assertJsonPath('error', 'connection_not_found');

        $mock->assertNotSent(DeleteConnectionRequest::class);
    });

    it('mints a Hub hosted connect-session URL', function (): void {
        $mock = new MockClient([
            \Emeq\HubSdk\Http\Request\ConnectSessions\CreateConnectSessionRequest::class => MockResponse::make([
                'url' => 'https://hub.example.test/connect/acc?signature=abc',
                'expires_at' => '2026-08-11T22:00:00+00:00',
            ], 200),
        ]);
        app(HubConnector::class)->withMockClient($mock);

        $this->postJson('/api/integrations/connect-session')
            ->assertOk()
            ->assertJsonPath('url', 'https://hub.example.test/connect/acc?signature=abc');

        $mock->assertSent(function (Request $request): bool {
            if (! $request instanceof \Emeq\HubSdk\Http\Request\ConnectSessions\CreateConnectSessionRequest) {
                return false;
            }

            $body = $request->body()->all();

            return ($body['account_external_id'] ?? null) === 'tenant-77'
                && ($body['display_name'] ?? null) === 'Demo BV'
                && str_ends_with((string) ($body['return_url'] ?? ''), '/integrations/oauth-callback');
        });
    });
});

it('returns 503 when ResolvesAccountId is not bound', function (): void {
    $response = $this->getJson('/api/integrations');

    $response->assertStatus(503)
        ->assertJsonPath('error', 'missing_account_resolver');
});
