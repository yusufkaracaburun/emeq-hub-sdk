<?php

declare(strict_types=1);

use Emeq\HubSdk\Contracts\ResolvesAccountId;
use Emeq\HubSdk\Exceptions\HubException;
use Emeq\HubSdk\Exceptions\PurchaseInFlight;
use Emeq\HubSdk\Http\HubConnector;
use Emeq\HubSdk\Http\Request\Itheorie\CreatePurchaseRequest;
use Emeq\HubSdk\Http\Request\Itheorie\GetCourseRequest;
use Emeq\HubSdk\Http\Request\Itheorie\GetPurchaseRequest;
use Emeq\HubSdk\Http\Request\Itheorie\GetStudentDetailedRequest;
use Emeq\HubSdk\Http\Request\Itheorie\GetStudentRequest;
use Emeq\HubSdk\Http\Request\Itheorie\ListCoursesRequest;
use Emeq\HubSdk\Hub;
use Emeq\HubSdk\Tests\Doubles\FixedAccountId;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('lists courses and keeps hub pagination links', function (): void {
    $mock = new MockClient([
        ListCoursesRequest::class => MockResponse::make([
            'links' => ['first' => null, 'previous' => null, 'self' => null, 'next' => null, 'last' => null],
            'data' => [['id' => 'c-1', 'title' => 'Auto']],
        ]),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    $courses = app(Hub::class)->itheorie()->courses(page: 2, limit: 25);

    expect($courses['data'][0]['id'])->toBe('c-1')
        ->and($courses)->toHaveKey('links');

    $mock->assertSent(function (ListCoursesRequest $request): bool {
        return $request->query()->all() === ['page' => 2, 'limit' => 25];
    });
});

it('sends no query at all when no page or limit is given', function (): void {
    $mock = new MockClient([
        ListCoursesRequest::class => MockResponse::make(['links' => [], 'data' => []]),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    app(Hub::class)->itheorie()->courses();

    $mock->assertSent(function (ListCoursesRequest $request): bool {
        return $request->query()->all() === [];
    });
});

it('escapes an access code on the way into the path', function (): void {
    $mock = new MockClient([
        GetStudentRequest::class => MockResponse::make(['id' => 's-1']),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    app(Hub::class)->itheorie()->student('AB/CD 12');

    $mock->assertSent(function (GetStudentRequest $request): bool {
        return $request->resolveEndpoint() === '/itheorie/students/AB%2FCD%2012';
    });
});

it('asks for the detailed student on its own endpoint', function (): void {
    $mock = new MockClient([
        GetStudentDetailedRequest::class => MockResponse::make(['id' => 's-1', 'progression' => null]),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    app(Hub::class)->itheorie()->studentDetailed('ABC1234');

    $mock->assertSent(function (GetStudentDetailedRequest $request): bool {
        return $request->resolveEndpoint() === '/itheorie/students/ABC1234/detailed';
    });
});

it('carries the caller idempotency key and never invents one', function (): void {
    $mock = new MockClient([
        CreatePurchaseRequest::class => MockResponse::make(['id' => 'p-1', 'access_code' => 'ABC1234']),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    app(Hub::class)->itheorie()->createPurchase(
        ['course' => 'c-1', 'name' => 'Jan', 'email' => 'jan@example.com'],
        'order-42',
    );

    expect($mock->getLastPendingRequest()?->headers()->get('Idempotency-Key'))->toBe('order-42');

    $mock->assertSent(function (CreatePurchaseRequest $request): bool {
        return $request->body()?->all() === [
            'course' => 'c-1',
            'name' => 'Jan',
            'email' => 'jan@example.com',
        ];
    });
});

it('keeps a purchase that came back without an access code or expiry', function (): void {
    $mock = new MockClient([
        GetPurchaseRequest::class => MockResponse::make([
            'id' => 'p-1',
            'access_code' => null,
            'expires_at' => null,
        ]),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    $purchase = app(Hub::class)->itheorie()->purchase('p-1');

    expect($purchase)->toHaveKeys(['access_code', 'expires_at'])
        ->and($purchase['access_code'])->toBeNull()
        ->and($purchase['expires_at'])->toBeNull();
});

it('sends no account header even when the consumer binds an account resolver', function (): void {
    app()->bind(ResolvesAccountId::class, fn (): ResolvesAccountId => new FixedAccountId('tenant-1'));

    $mock = new MockClient([
        ListCoursesRequest::class => MockResponse::make(['links' => [], 'data' => []]),
        GetCourseRequest::class => MockResponse::make(['id' => 'c-1']),
        CreatePurchaseRequest::class => MockResponse::make(['id' => 'p-1']),
        GetPurchaseRequest::class => MockResponse::make(['id' => 'p-1']),
        GetStudentRequest::class => MockResponse::make(['id' => 's-1']),
        GetStudentDetailedRequest::class => MockResponse::make(['id' => 's-1']),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    $itheorie = app(Hub::class)->itheorie();

    $itheorie->courses();
    $itheorie->course('c-1');
    $itheorie->createPurchase(['course' => 'c-1'], 'order-1');
    $itheorie->purchase('p-1');
    $itheorie->student('ABC1234');
    $itheorie->studentDetailed('ABC1234');

    $mock->assertSentCount(6);

    foreach ($mock->getRecordedResponses() as $response) {
        expect($response->getPendingRequest()->headers()->get('X-Account-Id'))->toBeNull();
    }
});

it('gives a conflicted purchase its own exception so it is never retried blindly', function (): void {
    $mock = new MockClient([
        CreatePurchaseRequest::class => MockResponse::make([
            'error' => 'purchase_in_flight',
            'category' => 'CONFLICT',
            'retryable' => false,
            'message' => 'Een eerdere poging met deze sleutel is afgebroken zonder bekende uitkomst.',
            'request_id' => 'req_1',
        ], 409),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    try {
        app(Hub::class)->itheorie()->createPurchase(['course' => 'c-1'], 'order-42');
        test()->fail('Expected PurchaseInFlight');
    } catch (PurchaseInFlight $e) {
        expect($e->error)->toBe('purchase_in_flight')
            ->and($e->status)->toBe(409)
            ->and($e->retryable)->toBeFalse();
    }
});

it('leaves every other conflict on the plain hub exception', function (): void {
    $mock = new MockClient([
        CreatePurchaseRequest::class => MockResponse::make([
            'error' => 'idempotency_request_in_progress',
            'category' => 'CONFLICT',
            'retryable' => true,
            'message' => 'Request in progress.',
            'request_id' => 'req_2',
        ], 409),
    ]);

    app(HubConnector::class)->withMockClient($mock);

    try {
        app(Hub::class)->itheorie()->createPurchase(['course' => 'c-1'], 'order-42');
        test()->fail('Expected HubException');
    } catch (HubException $e) {
        expect($e)->not->toBeInstanceOf(PurchaseInFlight::class)
            ->and($e->error)->toBe('idempotency_request_in_progress');
    }
});
