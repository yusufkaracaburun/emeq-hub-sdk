<?php

declare(strict_types=1);

use Emeq\HubSdk\Contracts\ResolvesAccountId;
use Emeq\HubSdk\Http\HubConnector;
use Emeq\HubSdk\Http\Request\ConnectSessions\CreateConnectSessionRequest;
use Emeq\HubSdk\Http\Request\Integrations\ListIntegrationsRequest;
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

    it('mints a Hub hosted connect-session URL', function (): void {
        $mock = new MockClient([
            CreateConnectSessionRequest::class => MockResponse::make([
                'url' => 'https://hub.example.test/connect/acc?signature=abc',
                'expires_at' => '2026-08-11T22:00:00+00:00',
            ], 200),
        ]);
        app(HubConnector::class)->withMockClient($mock);

        $this->postJson('/api/integrations/connect-session')
            ->assertOk()
            ->assertJsonPath('url', 'https://hub.example.test/connect/acc?signature=abc');

        $mock->assertSent(function (Request $request): bool {
            if (! $request instanceof CreateConnectSessionRequest) {
                return false;
            }

            $body = $request->body()->all();

            return ($body['account_external_id'] ?? null) === 'tenant-77'
                && ($body['display_name'] ?? null) === 'Demo BV'
                && str_ends_with((string) ($body['return_url'] ?? ''), '/integrations/oauth-callback');
        });
    });

    it('connect-session narrows non-string Hub fields to null instead of leaking them', function (): void {
        $mock = new MockClient([
            CreateConnectSessionRequest::class => MockResponse::make([
                'url' => ['not', 'a', 'string'],
                'expires_at' => 1234567890,
            ], 200),
        ]);
        app(HubConnector::class)->withMockClient($mock);

        $this->postJson('/api/integrations/connect-session')
            ->assertOk()
            ->assertExactJson(['url' => null, 'expires_at' => null]);
    });

    it('connect-session omits return_url when return_path is empty', function (): void {
        config(['hub.oauth.return_path' => '']);

        $mock = new MockClient([
            CreateConnectSessionRequest::class => MockResponse::make([
                'url' => 'https://hub.example.test/connect/acc?signature=abc',
                'expires_at' => '2026-08-11T22:00:00+00:00',
            ], 200),
        ]);
        app(HubConnector::class)->withMockClient($mock);

        $this->postJson('/api/integrations/connect-session')->assertOk();

        $mock->assertSent(function (Request $request): bool {
            if (! $request instanceof CreateConnectSessionRequest) {
                return false;
            }

            return ! array_key_exists('return_url', $request->body()->all());
        });
    });

    it('connect-session rejects absolute return_path from config', function (): void {
        config(['hub.oauth.return_path' => 'https://evil.example/phish']);

        $response = $this->postJson('/api/integrations/connect-session');

        $response->assertStatus(503)
            ->assertJsonPath('error', 'invalid_return_path');
    });

    it('does not expose per-provider connect or destroy BFF routes', function (): void {
        $connect = $this->postJson('/api/integrations/exact/connect');
        expect($connect->status())->toBeIn([404, 405]);

        $destroy = $this->deleteJson('/api/integrations/99');
        expect($destroy->status())->toBeIn([404, 405]);
    });
});

it('returns 503 when ResolvesAccountId is not bound', function (): void {
    $response = $this->getJson('/api/integrations');

    $response->assertStatus(503)
        ->assertJsonPath('error', 'missing_account_resolver');
});
