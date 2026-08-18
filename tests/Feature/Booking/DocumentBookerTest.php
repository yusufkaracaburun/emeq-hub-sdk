<?php

declare(strict_types=1);

use Emeq\HubSdk\Booking\AccountingChangeRecorder;
use Emeq\HubSdk\Booking\DocumentBooker;
use Emeq\HubSdk\Booking\HubDocument;
use Emeq\HubSdk\Exceptions\BookingAlreadyInProgress;
use Emeq\HubSdk\Exceptions\BookingTemporarilyUnavailable;
use Emeq\HubSdk\Exceptions\MissingConfigurationException;
use Emeq\HubSdk\Http\HubConnector;
use Emeq\HubSdk\Http\Request\Accounting\CreateDocumentRequest;
use Emeq\HubSdk\Testing\HubMock;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

function canonicalDocument(array $overrides = []): array
{
    return array_merge([
        'type' => 'sales_invoice',
        'external_id' => 'inv-1',
        'number' => '2026-001',
        'currency' => 'EUR',
        'issue_date' => '2026-08-16',
        'party' => ['role' => 'debtor', 'kind' => 'company', 'name' => 'Acme', 'external_id' => 'company-5'],
        'lines' => [['description' => 'Werk', 'amount' => 100.0, 'tax_rate' => 21.0]],
    ], $overrides);
}

function mockHub(MockResponse $response): MockClient
{
    $mock = new MockClient([CreateDocumentRequest::class => $response]);

    app(HubConnector::class)->withMockClient($mock);

    return $mock;
}

function booker(): DocumentBooker
{
    return app(DocumentBooker::class);
}

function hubError(string $error, int $status = 422, string $category = 'VALIDATION_ERROR', array $headers = [], ?bool $retryable = null): MockResponse
{
    return MockResponse::make(array_filter([
        'error' => $error,
        'message' => "Hub says {$error}.",
        'category' => $category,
        'retryable' => $retryable,
        'request_id' => '01JZZ0000000000000000000RQ',
    ], static fn (mixed $value): bool => $value !== null), $status, $headers);
}

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('records what the bookkeeping answered', function (): void {
    $mock = mockHub(HubMock::createDocument());

    $record = booker()->book(canonicalDocument());

    expect($record->status)->toBe(HubDocument::STATUS_POSTED)
        ->and($record->external_ref)->toBe('55555555-5555-4555-8555-555555555555')
        ->and($record->external_number)->toBe('26800003')
        ->and($record->booked_at)->not->toBeNull()
        ->and($record->account_id)->toBe('tenant-1')
        ->and($record->type)->toBe('sales_invoice')
        ->and($record->party_external_id)->toBe('company-5');

    $mock->assertSent(function (CreateDocumentRequest $request): bool {
        return $request->headers()->get('Idempotency-Key') === 'inv-1';
    });
});

it('will not resend a document that is already in the bookkeeping', function (): void {
    HubDocument::query()->create([
        'account_id' => 'tenant-1',
        'type' => 'sales_invoice',
        'external_id' => 'inv-1',
        'status' => HubDocument::STATUS_POSTED,
    ]);

    $mock = mockHub(HubMock::createDocument());

    $record = booker()->book(canonicalDocument());

    expect($record->status)->toBe(HubDocument::STATUS_POSTED);
    $mock->assertNothingSent();
});

it('records a refusal as rejected, not as failed', function (): void {
    mockHub(hubError('document_already_posted'));

    $record = booker()->book(canonicalDocument());

    expect($record->status)->toBe(HubDocument::STATUS_REJECTED)
        ->and($record->error)->toBe('document_already_posted')
        ->and($record->error_message)->toBe('Hub says document_already_posted.');
});

it('records an unexpected Hub error as failed', function (): void {
    mockHub(hubError('mapping_failed'));

    $record = booker()->book(canonicalDocument());

    expect($record->status)->toBe(HubDocument::STATUS_FAILED)
        ->and($record->error)->toBe('mapping_failed');
});

it('leaves no row when Hub is still working on the same key', function (): void {
    mockHub(hubError('idempotency_request_in_progress', 409, 'CONFLICT'));

    expect(fn () => booker()->book(canonicalDocument()))
        ->toThrow(BookingAlreadyInProgress::class);

    expect(HubDocument::query()->count())->toBe(0);
});

it('leaves no row on a rate limit or an upstream outage', function (int $status): void {
    mockHub(hubError('upstream_unavailable', $status, 'SERVER_ERROR'));

    expect(fn () => booker()->book(canonicalDocument()))
        ->toThrow(BookingTemporarilyUnavailable::class);

    expect(HubDocument::query()->count())->toBe(0);
})->with([429, 500, 503]);

it('records an interrupted send as unknown rather than losing the attempt', function (): void {
    mockHub(MockResponse::make()->throw(new RuntimeException('cURL error 28: Operation timed out')));

    $record = booker()->book(canonicalDocument());

    expect($record->status)->toBe(HubDocument::STATUS_UNKNOWN)
        ->and($record->error)->toBe('connection_interrupted')
        ->and($record->booked_at)->toBeNull();
});

it('sends nothing when the attachment cannot be produced', function (): void {
    $mock = mockHub(HubMock::createDocument());

    $record = booker()->book(
        canonicalDocument(),
        attachments: fn () => throw new RuntimeException('template missing'),
    );

    expect($record->status)->toBe(HubDocument::STATUS_FAILED)
        ->and($record->error)->toBe('attachment_render_failed')
        ->and($record->error_message)->toBe('template missing');

    $mock->assertNothingSent();
});

it('carries rendered attachments and drops an empty set', function (): void {
    $attachment = ['filename' => 'f.pdf', 'mime_type' => 'application/pdf', 'content' => 'Zm9v'];

    $mock = mockHub(HubMock::createDocument());
    booker()->book(canonicalDocument(), attachments: fn () => [$attachment]);

    $mock->assertSent(function (CreateDocumentRequest $request) use ($attachment): bool {
        return $request->body()->all()['attachments'] === [$attachment];
    });

    MockClient::destroyGlobal();

    $empty = mockHub(HubMock::createDocument());
    booker()->book(canonicalDocument(['external_id' => 'inv-2']), attachments: fn () => []);

    $empty->assertSent(function (CreateDocumentRequest $request): bool {
        return ! array_key_exists('attachments', $request->body()->all());
    });
});

it('keeps sending the relation key the first attempt used', function (): void {
    HubDocument::query()->create([
        'account_id' => 'tenant-1',
        'type' => 'sales_invoice',
        'external_id' => 'inv-1',
        'status' => HubDocument::STATUS_FAILED,
        'party_external_id' => 'company-5',
    ]);

    $mock = mockHub(HubMock::createDocument());

    booker()->book(canonicalDocument(['party' => ['role' => 'debtor', 'kind' => 'company', 'name' => 'Acme', 'external_id' => 'customer-9']]));

    $mock->assertSent(function (CreateDocumentRequest $request): bool {
        return $request->body()->all()['party']['external_id'] === 'company-5';
    });
});

it('sends the party as given — kind and external_id are the caller\'s to set', function (): void {
    $mock = mockHub(HubMock::createDocument());

    booker()->book(canonicalDocument());

    $mock->assertSent(function (CreateDocumentRequest $request): bool {
        $party = $request->body()->all()['party'];

        return $party['kind'] === 'company' && $party['external_id'] === 'company-5';
    });
});

it('never sends create_if_missing — the Hub decides whether to create the relation', function (): void {
    $mock = mockHub(HubMock::createDocument());

    booker()->book(canonicalDocument());

    $mock->assertSent(function (CreateDocumentRequest $request): bool {
        return ! array_key_exists('create_if_missing', $request->body()->all()['party']);
    });
});

it('carries what Hub reported about the relation on the posted record', function (): void {
    mockHub(HubMock::createDocumentWithWarnings());

    $record = booker()->book(canonicalDocument());

    expect($record->warnings)->toBe([
        [
            'code' => 'relation.matched_by_name',
            'message' => "Relatie 'Fixture Capture B.V.' is herkend op naam in plaats van KvK- of btw-nummer.",
            'context' => [
                'relation_id' => '77777777-7777-4777-8777-777777777777',
                'matched_name' => 'Fixture Capture B.V.',
            ],
        ],
    ]);
});

it('reports no warnings when Hub sends none', function (): void {
    mockHub(HubMock::createDocument());

    $record = booker()->book(canonicalDocument());

    expect($record->warnings)->toBe([]);
});

it('refuses to queue behind a booking that is already running', function (): void {
    mockHub(HubMock::createDocument());

    $held = Cache::lock('hub-document-booking:tenant-1:inv-1', 40);
    $held->get();

    expect(fn () => booker()->book(canonicalDocument()))
        ->toThrow(BookingAlreadyInProgress::class);

    expect(HubDocument::query()->count())->toBe(0);

    $held->release();
});

it('refuses a document with no external id, which is also its idempotency key', function (): void {
    mockHub(HubMock::createDocument());

    expect(fn () => booker()->book(canonicalDocument(['external_id' => ''])))
        ->toThrow(InvalidArgumentException::class);
});

it('treats both of Hub\'s "already running" answers as undecided', function (string $error): void {
    mockHub(hubError($error, 409, 'CONFLICT'));

    expect(fn () => booker()->book(canonicalDocument()))
        ->toThrow(BookingAlreadyInProgress::class);

    expect(HubDocument::query()->count())->toBe(0);
})->with(['idempotency_request_in_progress', 'document_sync_in_progress']);

it('carries the wait Hub asked for, so a retry does not have to guess', function (): void {
    mockHub(hubError('upstream_unavailable', 429, 'RATE_LIMIT', ['Retry-After' => '17']));

    try {
        booker()->book(canonicalDocument());
    } catch (BookingTemporarilyUnavailable $e) {
        expect($e->retryAfter)->toBe(17);

        return;
    }

    $this->fail('Expected a BookingTemporarilyUnavailable.');
});

it('reads a Retry-After Hub sent as an HTTP date', function (): void {
    Carbon::setTestNow('2026-10-21T07:27:00+00:00');

    mockHub(hubError('upstream_unavailable', 429, 'RATE_LIMIT', ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT']));

    try {
        booker()->book(canonicalDocument());
    } catch (BookingTemporarilyUnavailable $e) {
        expect($e->retryAfter)->toBe(60);

        return;
    }

    $this->fail('Expected a BookingTemporarilyUnavailable.');
});

it('floors an HTTP date Retry-After that already passed', function (): void {
    Carbon::setTestNow('2026-10-21T09:00:00+00:00');

    mockHub(hubError('upstream_unavailable', 429, 'RATE_LIMIT', ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT']));

    try {
        booker()->book(canonicalDocument());
    } catch (BookingTemporarilyUnavailable $e) {
        expect($e->retryAfter)->toBe(0);

        return;
    }

    $this->fail('Expected a BookingTemporarilyUnavailable.');
});

it('ignores a Retry-After it cannot read at all', function (): void {
    mockHub(hubError('upstream_unavailable', 429, 'RATE_LIMIT', ['Retry-After' => 'zo snel mogelijk']));

    try {
        booker()->book(canonicalDocument());
    } catch (BookingTemporarilyUnavailable $e) {
        expect($e->retryAfter)->toBeNull();

        return;
    }

    $this->fail('Expected a BookingTemporarilyUnavailable.');
});

it('offers an interrupted document again and settles it on Hub\'s replay', function (): void {
    HubDocument::query()->create([
        'account_id' => 'tenant-1',
        'type' => 'sales_invoice',
        'external_id' => 'inv-1',
        'status' => HubDocument::STATUS_UNKNOWN,
        'error' => 'connection_interrupted',
    ]);

    mockHub(HubMock::createDocument());

    $record = booker()->book(canonicalDocument());

    expect($record->status)->toBe(HubDocument::STATUS_POSTED)
        ->and($record->error)->toBeNull()
        ->and(HubDocument::query()->count())->toBe(1);
});

it('keeps the posted answer when a second attempt lost the race to the ledger', function (): void {
    $mock = new MockClient([
        CreateDocumentRequest::class => function () {
            HubDocument::query()->create([
                'account_id' => 'tenant-1',
                'type' => 'sales_invoice',
                'external_id' => 'inv-1',
                'status' => HubDocument::STATUS_FAILED,
                'error' => 'mapping_failed',
            ]);

            return HubMock::createDocument();
        },
    ]);

    app(HubConnector::class)->withMockClient($mock);

    $record = booker()->book(canonicalDocument());

    expect($record->status)->toBe(HubDocument::STATUS_POSTED)
        ->and($record->external_ref)->toBe('55555555-5555-4555-8555-555555555555')
        ->and($record->error)->toBeNull()
        ->and(HubDocument::query()->count())->toBe(1);
});

it('does not demote a document the winner already booked', function (): void {
    $mock = new MockClient([
        CreateDocumentRequest::class => function () {
            HubDocument::query()->create([
                'account_id' => 'tenant-1',
                'type' => 'sales_invoice',
                'external_id' => 'inv-1',
                'status' => HubDocument::STATUS_POSTED,
                'external_ref' => 'winner-ref',
            ]);

            return hubError('mapping_failed');
        },
    ]);

    app(HubConnector::class)->withMockClient($mock);

    $record = booker()->book(canonicalDocument());

    expect($record->status)->toBe(HubDocument::STATUS_POSTED)
        ->and($record->external_ref)->toBe('winner-ref')
        ->and(HubDocument::query()->count())->toBe(1);
});

it('refuses a lock that would expire while the send is still in flight', function (): void {
    config()->set('hub.timeout', 30);
    config()->set('hub.booking.lock_seconds', 30);

    mockHub(HubMock::createDocument());

    expect(fn () => booker()->book(canonicalDocument()))
        ->toThrow(MissingConfigurationException::class);

    expect(HubDocument::query()->count())->toBe(0);
});

it('records which Hub request decided a failure', function (): void {
    mockHub(hubError('mapping_failed'));

    $record = booker()->book(canonicalDocument());

    expect($record->status)->toBe(HubDocument::STATUS_FAILED)
        ->and($record->request_id)->toBe('01JZZ0000000000000000000RQ')
        ->and($record->category)->toBe('VALIDATION_ERROR');
});

it('clears the trace once the document is booked', function (): void {
    mockHub(hubError('mapping_failed'));
    booker()->book(canonicalDocument());

    mockHub(HubMock::createDocument());
    $record = booker()->book(canonicalDocument());

    expect($record->status)->toBe(HubDocument::STATUS_POSTED)
        ->and($record->request_id)->toBeNull()
        ->and($record->category)->toBeNull();
});

it('leaves no trace on a failure Hub never answered', function (): void {
    mockHub(hubError('mapping_failed'));
    booker()->book(canonicalDocument());

    mockHub(HubMock::createDocument());

    $record = booker()->book(canonicalDocument(), fn () => throw new RuntimeException('pdf stuk'));

    expect($record->error)->toBe('attachment_render_failed')
        ->and($record->request_id)->toBeNull()
        ->and($record->category)->toBeNull();
});

it('waits when Hub says the answer is retryable, even for a code it has never seen', function (): void {
    mockHub(hubError('some_new_lock', 409, 'CONFLICT', retryable: true));

    expect(fn () => booker()->book(canonicalDocument()))
        ->toThrow(BookingAlreadyInProgress::class);

    expect(HubDocument::query()->count())->toBe(0);
});

it('reads rejection off the category once Hub has an opinion', function (): void {
    mockHub(hubError('some_new_refusal', 422, 'PROVIDER_ERROR', retryable: false));

    $record = booker()->book(canonicalDocument());

    expect($record->status)->toBe(HubDocument::STATUS_REJECTED)
        ->and($record->error)->toBe('some_new_refusal');
});

it('keeps a fixable failure out of the rejected column', function (): void {
    mockHub(hubError('mapping_failed', 422, 'REFERENCE_MAPPING_MISSING', retryable: false));

    expect(booker()->book(canonicalDocument())->status)->toBe(HubDocument::STATUS_FAILED);
});

it('falls back to its own code list against a Hub that sends no opinion', function (): void {
    mockHub(hubError('upstream_rejected'));

    expect(booker()->book(canonicalDocument())->status)->toBe(HubDocument::STATUS_REJECTED);
});

it('books on against a ledger without the trace columns', function (): void {
    Schema::table((new HubDocument)->getTable(), function (Blueprint $table): void {
        $table->dropColumn(HubDocument::TRACE_COLUMNS);
    });
    HubDocument::forgetTraceSupport();

    mockHub(hubError('mapping_failed'));

    $record = booker()->book(canonicalDocument());

    expect($record->status)->toBe(HubDocument::STATUS_FAILED)
        ->and($record->error)->toBe('mapping_failed');
});

it('always leaves a message on the row, so Hub keeps the last word', function (): void {
    mockHub(MockResponse::make(['error' => 'mapping_failed', 'category' => 'REFERENCE_MAPPING_MISSING'], 422));

    $record = booker()->book(canonicalDocument());

    expect($record->error_message)->toBe('mapping_failed')
        ->and($record->error_message)->not->toBe('');
});

function deletedInAccounting(array $overrides = []): HubDocument
{
    return HubDocument::query()->create(array_merge([
        'account_id' => 'tenant-1',
        'type' => 'sales_invoice',
        'external_id' => 'inv-1',
        'status' => HubDocument::STATUS_POSTED,
        'external_ref' => '11111111-1111-4111-8111-111111111111',
        'external_number' => '26800001',
        'booked_at' => Carbon::parse('2026-08-18 09:00:00'),
        'accounting_changed_at' => Carbon::parse('2026-08-18 14:00:00'),
        'accounting_change_action' => 'deleted',
        'accounting_change_event_id' => 'evt-del-1',
    ], $overrides));
}

it('sends a document the bookkeeping threw away again', function (): void {
    deletedInAccounting();

    $mock = mockHub(HubMock::createDocument());

    $record = booker()->book(canonicalDocument());

    expect($record->status)->toBe(HubDocument::STATUS_POSTED)
        ->and($record->external_ref)->toBe('55555555-5555-4555-8555-555555555555')
        ->and($record->external_number)->toBe('26800003');

    $mock->assertSent(CreateDocumentRequest::class);
});

it('gives the second send a key of its own, so Hub cannot replay the first answer', function (): void {
    deletedInAccounting();

    $mock = mockHub(HubMock::createDocument());

    booker()->book(canonicalDocument());

    $mock->assertSent(function (CreateDocumentRequest $request): bool {
        return $request->headers()->get('Idempotency-Key') === 'inv-1:evt-del-1';
    });
});

it('holds the same key across a retry of one re-send', function (): void {
    deletedInAccounting(['accounting_change_event_id' => null]);

    $first = mockHub(hubError('mapping_failed'));
    booker()->book(canonicalDocument());
    $key = $first->getLastRequest()->headers()->get('Idempotency-Key');

    $second = mockHub(hubError('mapping_failed'));
    booker()->book(canonicalDocument());

    expect($second->getLastRequest()->headers()->get('Idempotency-Key'))->toBe($key)
        ->and($key)->not->toBe('inv-1');
});

it('forgets what the bookkeeping did once the document is booked again', function (): void {
    deletedInAccounting();

    mockHub(HubMock::createDocument());

    $record = booker()->book(canonicalDocument());

    expect($record->refresh()->accounting_changed_at)->toBeNull()
        ->and($record->accounting_change_action)->toBeNull()
        ->and($record->accounting_change_event_id)->toBeNull();
});

it('will not resend a document the bookkeeping only edited', function (): void {
    deletedInAccounting(['accounting_change_action' => 'updated']);

    $mock = mockHub(HubMock::createDocument());

    $record = booker()->book(canonicalDocument());

    expect($record->external_number)->toBe('26800001');
    $mock->assertNothingSent();
});

it('books a deleted document again against a ledger without the change columns', function (): void {
    HubDocument::query()->create([
        'account_id' => 'tenant-1',
        'type' => 'sales_invoice',
        'external_id' => 'inv-1',
        'status' => HubDocument::STATUS_POSTED,
        'external_number' => '26800001',
    ]);

    Schema::table((new HubDocument)->getTable(), function (Blueprint $table): void {
        $table->dropColumn(HubDocument::CHANGE_COLUMNS);
    });
    AccountingChangeRecorder::forgetChangeSupport();

    $mock = mockHub(HubMock::createDocument());

    expect(booker()->book(canonicalDocument())->external_number)->toBe('26800001');
    $mock->assertNothingSent();
});
