<?php

declare(strict_types=1);

use Emeq\HubSdk\Booking\BookableDocument;
use Emeq\HubSdk\Booking\BookingRunner;
use Emeq\HubSdk\Booking\Contracts\ResolvesBookableDocument;
use Emeq\HubSdk\Events\DocumentBooked;
use Emeq\HubSdk\Events\DocumentBookingFailed;
use Emeq\HubSdk\Exceptions\DocumentNotAuthorized;
use Emeq\HubSdk\Exceptions\DocumentNotBookable;
use Emeq\HubSdk\Testing\HubMock;
use Emeq\HubSdk\Tests\Doubles\FakeBookableDocuments;
use Illuminate\Support\Facades\Event;
use Saloon\Http\Faking\MockClient;

/** @param  array<string, BookableDocument|Throwable>  $map */
function runnerFor(array $map): BookingRunner
{
    app()->bind(ResolvesBookableDocument::class, fn (): ResolvesBookableDocument => new FakeBookableDocuments($map));

    return app(BookingRunner::class);
}

function bookableInvoice(mixed $subject = null): BookableDocument
{
    return new BookableDocument(
        document: [
            'type' => 'sales_invoice',
            'external_id' => 'invoice-1',
            'party' => ['role' => 'debtor', 'kind' => 'company', 'name' => 'Acme', 'external_id' => 'company-1'],
            'lines' => [['description' => 'Work', 'amount' => 100.0, 'tax_rate' => 21.0]],
        ],
        subject: $subject,
    );
}

test('a booked document announces itself with the record the consumer hung on it', function () {
    Event::fake([DocumentBooked::class, DocumentBookingFailed::class]);
    MockClient::global([HubMock::createDocument()]);

    $subject = new stdClass;
    runnerFor(['invoice:invoice-1' => bookableInvoice($subject)])->bookOne('invoice', 'invoice-1');

    Event::assertDispatched(DocumentBooked::class, function (DocumentBooked $event) use ($subject): bool {
        return $event->module === 'invoice'
            && $event->id === 'invoice-1'
            && $event->subject === $subject
            && $event->outcome->booked;
    });
    Event::assertNotDispatched(DocumentBookingFailed::class);

    MockClient::destroyGlobal();
});

test('a document the mapper refuses announces a failure and never reaches hub', function () {
    Event::fake([DocumentBooked::class, DocumentBookingFailed::class]);

    runnerFor(['invoice:invoice-1' => new DocumentNotBookable('Still a draft')])
        ->bookOne('invoice', 'invoice-1');

    Event::assertDispatched(DocumentBookingFailed::class, function (DocumentBookingFailed $event): bool {
        return $event->outcome->status === 422
            && $event->outcome->message === 'Still a draft'
            && $event->subject === null;
    });
    Event::assertNotDispatched(DocumentBooked::class);
});

test('an unauthorised document announces a failure', function () {
    Event::fake([DocumentBookingFailed::class]);

    runnerFor(['invoice:invoice-1' => new DocumentNotAuthorized])->bookOne('invoice', 'invoice-1');

    Event::assertDispatched(DocumentBookingFailed::class, fn (DocumentBookingFailed $e): bool => $e->outcome->status === 403);
});

test('a missing document announces a failure', function () {
    Event::fake([DocumentBookingFailed::class]);

    runnerFor([])->bookOne('invoice', 'nope');

    Event::assertDispatched(DocumentBookingFailed::class, fn (DocumentBookingFailed $e): bool => $e->outcome->status === 404);
});

test('a batch announces once per document', function () {
    Event::fake([DocumentBooked::class, DocumentBookingFailed::class]);
    MockClient::global([HubMock::createDocument()]);

    runnerFor([
        'invoice:invoice-1' => bookableInvoice(),
        'invoice:missing' => new DocumentNotBookable('Still a draft'),
    ])->book([
        ['module' => 'invoice', 'id' => 'invoice-1'],
        ['module' => 'invoice', 'id' => 'missing'],
    ]);

    Event::assertDispatchedTimes(DocumentBooked::class, 1);
    Event::assertDispatchedTimes(DocumentBookingFailed::class, 1);

    MockClient::destroyGlobal();
});

test('a check announces nothing — it books no document', function () {
    Event::fake([DocumentBooked::class, DocumentBookingFailed::class]);
    MockClient::global([HubMock::validateDocument()]);

    runnerFor(['invoice:invoice-1' => bookableInvoice()])->checkOne('invoice', 'invoice-1');

    Event::assertNotDispatched(DocumentBooked::class);
    Event::assertNotDispatched(DocumentBookingFailed::class);

    MockClient::destroyGlobal();
});
