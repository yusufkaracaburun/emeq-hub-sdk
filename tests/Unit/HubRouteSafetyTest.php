<?php

declare(strict_types=1);

use Emeq\HubSdk\Exceptions\MissingConfigurationException;
use Emeq\HubSdk\Support\HubRouteMiddleware;
use Emeq\HubSdk\Support\OAuthReturnUrl;
use Illuminate\Http\Request;

it('normalizes comma-separated middleware and drops empties', function (): void {
    expect(HubRouteMiddleware::normalize('api, auth:sanctum , ,web'))
        ->toBe(['api', 'auth:sanctum', 'web']);
});

it('refuses empty middleware', function (): void {
    HubRouteMiddleware::assertNotEmpty([]);
})->throws(InvalidArgumentException::class, 'must not be empty');

it('builds return url from a relative path', function (): void {
    $request = Request::create('https://app.example.test/api/integrations/exact/connect', 'POST');

    expect(OAuthReturnUrl::fromConfigPath($request, '/settings?oauth=1'))
        ->toBe('https://app.example.test/settings?oauth=1');
});

it('returns null for empty return path', function (): void {
    $request = Request::create('https://app.example.test/', 'POST');

    expect(OAuthReturnUrl::fromConfigPath($request, '  '))->toBeNull();
});

it('rejects absolute and protocol-relative return paths', function (string $path): void {
    $request = Request::create('https://app.example.test/', 'POST');
    OAuthReturnUrl::fromConfigPath($request, $path);
})->with([
    'https://evil.example/phish',
    'http://evil.example/phish',
    '//evil.example/phish',
    '\\evil.example',
])->throws(MissingConfigurationException::class);

it('reports a bad return path as configuration, not caller validation', function (): void {
    $request = Request::create('https://app.example.test/', 'POST');

    try {
        OAuthReturnUrl::fromConfigPath($request, 'https://evil.example/phish');
        expect(false)->toBeTrue('Expected MissingConfigurationException');
    } catch (MissingConfigurationException $e) {
        // 503, not 422: the API caller cannot fix the consumer's config.
        expect($e->status)->toBe(503)
            ->and($e->category)->toBe('CONFIGURATION_ERROR');
    }
});
