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
    // packageBooted() calls validated() against this value, so a default that
    // fails it would make EMEQ_HUB_ROUTES=true unbootable out of the box.
    $default = require __DIR__.'/../../config/hub.php';
    config()->set('hub.routes', $default['routes']);

    expect(HubRouteMiddleware::validated())->toBe($default['routes']['middleware'])
        ->and($default['routes']['allow_unauthenticated'])->toBeFalse();
});

it('skips the auth assert only when allow_unauthenticated declares it deliberate', function (): void {
    config()->set('hub.routes.middleware', ['api']);
    config()->set('hub.routes.allow_unauthenticated', true);

    expect(HubRouteMiddleware::validated())->toBe(['api']);
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
})->with(['auth', 'auth:sanctum', 'auth:api', 'auth.basic'])->throwsNoExceptions();

it('refuses names Laravel cannot resolve to auth', function (string $entry): void {
    // Aliases resolve by exact array key, so `AUTH:SANCTUM` is passed through as
    // a class name; `auth.session` aliases AuthenticateSession, which
    // authenticates nobody. Either would pass a stack with no working auth.
    HubRouteMiddleware::assertAuthenticated(['api', $entry]);
})->with(['AUTH:SANCTUM', 'auth.session'])
    ->throws(InvalidArgumentException::class, 'no auth middleware');

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
