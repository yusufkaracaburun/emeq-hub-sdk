<?php

declare(strict_types=1);

use Emeq\HubSdk\Booking\BookableDocument;
use Emeq\HubSdk\Booking\BookingRunner;
use Emeq\HubSdk\Booking\Contracts\ResolvesBookableDocument;
use Emeq\HubSdk\Booking\HubDocument;
use Emeq\HubSdk\Booking\Resources\BatchResultResource;
use Emeq\HubSdk\Booking\Resources\CheckResultResource;
use Emeq\HubSdk\Exceptions\DocumentNotAuthorized;
use Emeq\HubSdk\Exceptions\DocumentNotBookable;
use Emeq\HubSdk\Http\HubConnector;
use Emeq\HubSdk\Http\Request\Accounting\CreateDocumentRequest;
use Emeq\HubSdk\Http\Request\Accounting\ValidateDocumentRequest;
use Emeq\HubSdk\Testing\HubMock;
use Emeq\HubSdk\Tests\Doubles\FakeBookableDocuments;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

function sendable(array $overrides = [], ?Closure $attachments = null): BookableDocument
{
    return new BookableDocument(array_merge([
        'type' => 'sales_invoice',
        'external_id' => 'inv-1',
        'party' => ['role' => 'debtor', 'name' => 'Acme'],
        'lines' => [['description' => 'Werk', 'amount' => 100.0, 'tax_rate' => 21.0]],
    ], $overrides), $attachments);
}

function runner(array $map): BookingRunner
{
    app()->instance(ResolvesBookableDocument::class, new FakeBookableDocuments($map));

    return app(BookingRunner::class);
}

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('turns a missing document into 404 and an unauthorised one into 403', function (): void {
    $runner = runner([
        'invoice:gone' => new ModelNotFoundException,
        'invoice:theirs' => new DocumentNotAuthorized,
    ]);

    expect($runner->bookOne('invoice', 'gone')->status)->toBe(404)
        ->and($runner->bookOne('invoice', 'theirs')->status)->toBe(403)
        ->and($runner->checkOne('invoice', 'gone')->message)->toBe('This document no longer exists.')
        ->and($runner->checkOne('invoice', 'theirs')->checked())->toBeFalse();
});

it('reports an unmappable document with the mapper\'s own reason', function (): void {
    $runner = runner(['invoice:draft' => new DocumentNotBookable('Invoice 7 cannot be booked: it is a draft.')]);

    $outcome = $runner->bookOne('invoice', 'draft');

    expect($outcome->status)->toBe(422)
        ->and($outcome->message)->toBe('Invoice 7 cannot be booked: it is a draft.')
        ->and($outcome->record)->toBeNull();
});

it('books what it resolves and reads the ledger row back', function (): void {
    app(HubConnector::class)->withMockClient(new MockClient([
        CreateDocumentRequest::class => HubMock::createDocument(),
    ]));

    $outcome = runner(['invoice:inv-1' => sendable()])->bookOne('invoice', 'inv-1');

    expect($outcome->booked)->toBeTrue()
        ->and($outcome->status)->toBe(200)
        ->and($outcome->record?->status)->toBe(HubDocument::STATUS_POSTED)
        ->and(HubDocument::query()->count())->toBe(1);
});

it('answers 503 when nothing was decided, so the caller may retry', function (): void {
    app(HubConnector::class)->withMockClient(new MockClient([
        CreateDocumentRequest::class => MockResponse::make([
            'error' => 'upstream_unavailable',
            'message' => 'Upstream is down.',
            'category' => 'SERVER_ERROR',
        ], 503),
    ]));

    $outcome = runner(['invoice:inv-1' => sendable()])->bookOne('invoice', 'inv-1');

    expect($outcome->mayRetry())->toBeTrue()
        ->and($outcome->status)->toBe(503)
        ->and(HubDocument::query()->count())->toBe(0);
});

it('sends attachments only when asked', function (): void {
    $attachment = ['filename' => 'f.pdf', 'mime_type' => 'application/pdf', 'content' => 'Zm9v'];

    $mock = new MockClient([CreateDocumentRequest::class => HubMock::createDocument()]);
    app(HubConnector::class)->withMockClient($mock);

    runner(['invoice:inv-1' => sendable(attachments: fn () => [$attachment])])
        ->bookOne('invoice', 'inv-1', withAttachment: false);

    $mock->assertSent(fn (CreateDocumentRequest $request): bool => ! array_key_exists('attachments', $request->body()->all()));
});

it('hands back Hub\'s verdict on a check', function (): void {
    app(HubConnector::class)->withMockClient(new MockClient([
        ValidateDocumentRequest::class => HubMock::validateDocument(valid: false),
    ]));

    $outcome = runner(['invoice:inv-1' => sendable()])->checkOne('invoice', 'inv-1');

    expect($outcome->checked())->toBeTrue()
        ->and($outcome->message)->toBeNull()
        ->and($outcome->retryable)->toBeFalse();

    $resource = (new CheckResultResource($outcome))->toArray(Request::create('/'));

    expect($resource['checked'])->toBeTrue()
        ->and($resource['valid'])->toBeFalse()
        ->and($resource['findings'])->not->toBeEmpty();
});

it('reports a Hub error on a check without claiming the document is wrong', function (): void {
    app(HubConnector::class)->withMockClient(new MockClient([
        ValidateDocumentRequest::class => HubMock::notFound(),
    ]));

    $outcome = runner(['invoice:inv-1' => sendable()])->checkOne('invoice', 'inv-1');

    expect($outcome->checked())->toBeFalse()
        ->and($outcome->message)->toBe('Connection niet gevonden.');
});

it('stops a batch once its time budget is spent', function (): void {
    config()->set('hub.booking.batch_seconds', 0);

    $runner = runner([
        'invoice:a' => new ModelNotFoundException,
        'invoice:b' => new ModelNotFoundException,
        'invoice:c' => new ModelNotFoundException,
    ]);

    $requested = [
        ['module' => 'invoice', 'id' => 'a'],
        ['module' => 'invoice', 'id' => 'b'],
        ['module' => 'invoice', 'id' => 'c'],
    ];

    expect($runner->book($requested))->toHaveCount(1)
        ->and($runner->check($requested))->toHaveCount(1);
});

it('labels every batch result with the document it came from', function (): void {
    app(HubConnector::class)->withMockClient(new MockClient([
        CreateDocumentRequest::class => HubMock::createDocument(),
    ]));

    $results = runner([
        'invoice:inv-1' => sendable(),
        'transaction:gone' => new ModelNotFoundException,
    ])->book([
        ['module' => 'invoice', 'id' => 'inv-1'],
        ['module' => 'transaction', 'id' => 'gone'],
    ]);

    expect($results)->toHaveCount(2)
        ->and($results[0]->module)->toBe('invoice')
        ->and($results[0]->outcome->booked)->toBeTrue()
        ->and($results[1]->id)->toBe('gone')
        ->and($results[1]->outcome->status)->toBe(404);

    $resource = (new BatchResultResource($results[1]))->toArray(Request::create('/'));

    expect($resource)->toMatchArray([
        'module' => 'transaction',
        'id' => 'gone',
        'booked' => false,
        'status' => 404,
        'may_retry' => false,
        'booking' => null,
    ]);
});
