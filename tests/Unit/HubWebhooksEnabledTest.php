<?php

declare(strict_types=1);

use Emeq\HubSdk\Http\Middleware\HubWebhooksEnabled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function passThrough(): Closure
{
    return fn (Request $request): Response => response('reached');
}

test('an open endpoint passes the delivery through', function () {
    config()->set('hub.webhook.enabled', true);

    $response = (new HubWebhooksEnabled)->handle(Request::create('/webhooks/emeq-hub', 'POST'), passThrough());

    expect($response->getContent())->toBe('reached');
});

test('a closed endpoint is indistinguishable from one that was never registered', function () {
    config()->set('hub.webhook.enabled', false);

    expect(fn () => (new HubWebhooksEnabled)->handle(Request::create('/webhooks/emeq-hub', 'POST'), passThrough()))
        ->toThrow(NotFoundHttpException::class);
});

test('an endpoint nobody configured is open — registering the route is the decision', function () {
    config()->set('hub.webhook', Arr::except((array) config('hub.webhook'), 'enabled'));

    $response = (new HubWebhooksEnabled)->handle(Request::create('/webhooks/emeq-hub', 'POST'), passThrough());

    expect($response->getContent())->toBe('reached');
});
