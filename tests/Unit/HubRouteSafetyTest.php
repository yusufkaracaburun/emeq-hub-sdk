<?php

declare(strict_types=1);

use Emeq\HubSdk\Exceptions\MissingConfigurationException;
use Emeq\HubSdk\Support\HubRouteMiddleware;
use Emeq\HubSdk\Support\OAuthReturnUrl;

it('normalizes comma-separated middleware and drops empties', function (): void {
    expect(HubRouteMiddleware::normalize('api, auth:sanctum , ,web'))
        ->toBe(['api', 'auth:sanctum', 'web']);
});

it('ships a throttled default middleware stack', function (): void {
    // The BFF fans out to the Hub API and Laravel's `api` group carries no
    // rate limiter of its own, so the shipped default must bring one.
    $default = require __DIR__.'/../../config/hub.php';

    expect($default['routes']['middleware'])->toBe(['api', 'auth:sanctum', 'throttle:60,1']);
});

it('ships a default the boot guard accepts', function (): void {
    // packageBooted() runs both asserts against this value, so a default that
    // fails them would make EMEQ_HUB_ROUTES=true unbootable out of the box.
    $default = require __DIR__.'/../../config/hub.php';

    HubRouteMiddleware::assertNotEmpty($default['routes']['middleware']);
    HubRouteMiddleware::assertAuthenticated(
        $default['routes']['middleware'],
        $default['routes']['allow_unauthenticated'],
    );

    expect($default['routes']['allow_unauthenticated'])->toBeFalse();
});

it('refuses empty middleware', function (): void {
    HubRouteMiddleware::assertNotEmpty([]);
})->throws(InvalidArgumentException::class, 'must not be empty');

it('refuses a middleware stack with no auth entry', function (): void {
    // assertNotEmpty used to carry this promise in its message without ever
    // checking it, so ['api'] registered an unauthenticated connect-session POST.
    HubRouteMiddleware::assertAuthenticated(['api', 'throttle:60,1']);
})->throws(InvalidArgumentException::class, 'no auth middleware');

it('accepts the auth middleware family', function (string $entry): void {
    HubRouteMiddleware::assertAuthenticated(['api', $entry]);

    expect(true)->toBeTrue();
})->with(['auth', 'auth:sanctum', 'auth:api', 'auth.basic', 'AUTH:SANCTUM']);

it('allows an unauthenticated stack only when declared deliberate', function (): void {
    HubRouteMiddleware::assertAuthenticated(['api'], allowUnauthenticated: true);

    expect(true)->toBeTrue();
});

it('builds return url from a relative path', function (): void {
    expect(OAuthReturnUrl::fromConfigPath('https://app.example.test', '/settings?oauth=1'))
        ->toBe('https://app.example.test/settings?oauth=1');
});

it('returns null for empty return path', function (): void {
    expect(OAuthReturnUrl::fromConfigPath('https://app.example.test', '  '))->toBeNull();
});

it('rejects absolute and protocol-relative return paths', function (string $path): void {
    OAuthReturnUrl::fromConfigPath('https://app.example.test', $path);
})->with([
    'https://evil.example/phish',
    'http://evil.example/phish',
    '//evil.example/phish',
    '\\evil.example',
])->throws(MissingConfigurationException::class);

it('reports a bad return path as configuration, not caller validation', function (): void {
    try {
        OAuthReturnUrl::fromConfigPath('https://app.example.test', 'https://evil.example/phish');
        expect(false)->toBeTrue('Expected MissingConfigurationException');
    } catch (MissingConfigurationException $e) {
        // 503, not 422: the API caller cannot fix the consumer's config.
        expect($e->status)->toBe(503)
            ->and($e->category)->toBe('CONFIGURATION_ERROR');
    }
});
