<?php

declare(strict_types=1);

use Emeq\HubSdk\Backlog\BacklogRepository;
use Emeq\HubSdk\Backlog\BacklogStatus;
use Emeq\HubSdk\Backlog\Resources\BacklogDocumentResource;
use Emeq\HubSdk\Booking\HubDocument;
use Emeq\HubSdk\Tests\Doubles\FakeBacklogSources;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

function documents(): Builder
{
    $connection = config('hub.booking.connection');

    return DB::connection(is_string($connection) && $connection !== '' ? $connection : null)
        ->table(FakeBacklogSources::TABLE);
}

function document(array $attributes = []): string
{
    $uuid = $attributes['uuid'] ?? 'doc-'.documents()->count();

    documents()->insert(array_merge([
        'module' => 'invoice',
        'uuid' => $uuid,
        'number' => '2026-001',
        'date' => '2026-08-01',
        'amount' => 121.00,
        'party' => 'Acme',
        'direction' => 'sales',
        'head' => null,
        'document_status' => 'sent',
    ], $attributes, ['uuid' => $uuid]));

    return $uuid;
}

function booking(string $uuid, string $status, array $attributes = []): HubDocument
{
    return HubDocument::query()->create(array_merge([
        'account_id' => 'tenant-1',
        'type' => 'sales_invoice',
        'external_id' => $uuid,
        'status' => $status,
    ], $attributes));
}

function backlog(): BacklogRepository
{
    return app(BacklogRepository::class);
}

it('answers not_booked for a document nobody has tried', function (): void {
    document(['uuid' => 'inv-1']);

    $rows = backlog()->paginate([])->items();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->status)->toBe(BacklogStatus::NOT_BOOKED)
        ->and($rows[0]->hub_document)->toBeNull();
});

it('drops a document once it is posted', function (): void {
    document(['uuid' => 'inv-1']);
    document(['uuid' => 'inv-2']);
    booking('inv-1', HubDocument::STATUS_POSTED);

    $rows = backlog()->paginate([])->items();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->uuid)->toBe('inv-2');
});

it('keeps a posted document of another account in this backlog', function (): void {
    document(['uuid' => 'inv-1']);
    booking('inv-1', HubDocument::STATUS_POSTED, ['account_id' => 'tenant-2']);

    expect(backlog()->paginate([])->items())->toHaveCount(1);
});

it('carries the whole ledger row, not just the joined status', function (): void {
    document(['uuid' => 'inv-1']);
    booking('inv-1', HubDocument::STATUS_REJECTED, [
        'error' => 'upstream_rejected',
        'error_message' => 'Ledger account 8000 does not exist.',
    ]);

    $row = backlog()->paginate([])->items()[0];

    expect($row->status)->toBe(HubDocument::STATUS_REJECTED)
        ->and($row->hub_document)->toBeInstanceOf(HubDocument::class)
        ->and($row->hub_document->error_message)->toBe('Ledger account 8000 does not exist.');

    $resource = (new BacklogDocumentResource($row))->toArray(Request::create('/'));

    expect($resource['status'])->toBe(HubDocument::STATUS_REJECTED)
        ->and($resource['booking']['error'])->toBe('upstream_rejected')
        ->and($resource['uuid'])->toBe('inv-1');
});

it('describes a document by its last attempt when the type changed', function (): void {
    document(['uuid' => 'inv-1']);
    booking('inv-1', HubDocument::STATUS_FAILED, ['type' => 'sales_invoice']);
    booking('inv-1', HubDocument::STATUS_REJECTED, ['type' => 'credit_note']);

    expect(backlog()->paginate([])->items()[0]->status)->toBe(HubDocument::STATUS_REJECTED);
});

it('filters on not_booked without losing the rows that have a status', function (): void {
    document(['uuid' => 'inv-1']);
    document(['uuid' => 'inv-2']);
    booking('inv-2', HubDocument::STATUS_FAILED);

    $onlyOpen = backlog()->paginate(['status' => [BacklogStatus::NOT_BOOKED]])->items();
    $both = backlog()->paginate(['status' => [BacklogStatus::NOT_BOOKED, HubDocument::STATUS_FAILED]])->items();

    expect($onlyOpen)->toHaveCount(1)
        ->and($onlyOpen[0]->uuid)->toBe('inv-1')
        ->and($both)->toHaveCount(2);
});

it('searches number and party, not the rest of the row', function (): void {
    document(['uuid' => 'inv-1', 'number' => '2026-007', 'party' => 'Acme']);
    document(['uuid' => 'inv-2', 'number' => '2026-008', 'party' => 'Globex']);

    expect(backlog()->paginate(['search_term' => '007'])->items())->toHaveCount(1)
        ->and(backlog()->paginate(['search_term' => 'globe'])->items())->toHaveCount(1)
        ->and(backlog()->paginate(['search_term' => 'sales'])->items())->toHaveCount(0);
});

it('narrows on period, direction and amount', function (): void {
    document(['uuid' => 'a', 'date' => '2026-01-10', 'amount' => 50.00, 'direction' => 'sales']);
    document(['uuid' => 'b', 'date' => '2026-08-10', 'amount' => 500.00, 'direction' => 'purchase', 'module' => 'transaction']);

    expect(backlog()->paginate(['start_date' => '2026-06-01'])->items())->toHaveCount(1)
        ->and(backlog()->paginate(['end_date' => '2026-06-01'])->items())->toHaveCount(1)
        ->and(backlog()->paginate(['direction' => 'purchase'])->items())->toHaveCount(1)
        ->and(backlog()->paginate(['min_amount' => 100])->items())->toHaveCount(1)
        ->and(backlog()->paginate(['max_amount' => 100])->items())->toHaveCount(1)
        ->and(backlog()->paginate(['modules' => ['transaction']])->items())->toHaveCount(1);
});

it('refuses a page longer than the ceiling', function (): void {
    document();

    expect(backlog()->paginate(['page_length' => 5000])->perPage())->toBe(BacklogRepository::MAX_PAGE_LENGTH)
        ->and(backlog()->paginate(['page_length' => 0])->perPage())->toBe(1)
        ->and(backlog()->paginate([])->perPage())->toBe(25);
});

it('sorts on the requested column and refuses anything else', function (): void {
    document(['uuid' => 'a', 'amount' => 10.00]);
    document(['uuid' => 'b', 'amount' => 90.00]);

    $descending = backlog()->paginate(['sort_by' => 'amount'])->items();
    $ascending = backlog()->paginate(['sort_by' => 'amount', 'order' => 'asc'])->items();
    $injected = backlog()->paginate(['sort_by' => 'amount; drop table hub_documents'])->items();

    expect($descending[0]->uuid)->toBe('b')
        ->and($ascending[0]->uuid)->toBe('a')
        ->and($injected)->toHaveCount(2);
});

it('drops a posted, unchanged document from the backlog, same as before', function (): void {
    document(['uuid' => 'inv-1']);
    booking('inv-1', HubDocument::STATUS_POSTED);

    expect(backlog()->paginate([])->items())->toHaveCount(0);
});

it('surfaces only the posted document the bookkeeping changed afterwards', function (): void {
    document(['uuid' => 'inv-1']);
    document(['uuid' => 'inv-2']);
    booking('inv-1', HubDocument::STATUS_POSTED, [
        'accounting_changed_at' => '2026-08-17T09:00:00+00:00',
        'accounting_change_action' => 'updated',
    ]);
    booking('inv-2', HubDocument::STATUS_POSTED);

    $rows = backlog()->paginate(['accounting_changed' => 1])->items();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->uuid)->toBe('inv-1');
});

it('composes the accounting-changed filter with a status filter, not replacing it', function (): void {
    document(['uuid' => 'inv-1']);
    document(['uuid' => 'inv-2']);
    booking('inv-2', HubDocument::STATUS_POSTED, ['accounting_changed_at' => '2026-08-17T09:00:00+00:00']);

    $onlyOpen = backlog()->paginate([
        'status' => [BacklogStatus::NOT_BOOKED],
        'accounting_changed' => 1,
    ])->items();

    expect($onlyOpen)->toHaveCount(0);
});

it('counts accounting-changed documents in the summary alongside by_status', function (): void {
    document(['uuid' => 'inv-1']);
    document(['uuid' => 'inv-2']);
    booking('inv-2', HubDocument::STATUS_POSTED, ['accounting_changed_at' => '2026-08-17T09:00:00+00:00']);

    $summary = backlog()->summary([]);

    expect($summary->accountingChanged)->toBe(1)
        ->and($summary->byStatus[BacklogStatus::NOT_BOOKED])->toBe(1);
});

it('exposes when and how the bookkeeping changed a document, on the row itself', function (): void {
    document(['uuid' => 'inv-1']);
    booking('inv-1', HubDocument::STATUS_POSTED, [
        'accounting_changed_at' => '2026-08-17T09:00:00+00:00',
        'accounting_change_action' => 'updated',
    ]);

    $row = backlog()->paginate(['accounting_changed' => 1])->items()[0];
    $resource = (new BacklogDocumentResource($row))->toArray(Request::create('/'));

    expect($resource['accounting_changed_at'])->toBe('2026-08-17T09:00:00+00:00')
        ->and($resource['accounting_change_action'])->toBe('updated');
});

it('reads accounting_changed_at as null for a document never reported changed', function (): void {
    document(['uuid' => 'inv-1']);

    $row = backlog()->paginate([])->items()[0];
    $resource = (new BacklogDocumentResource($row))->toArray(Request::create('/'));

    expect($resource['accounting_changed_at'])->toBeNull()
        ->and($resource['accounting_change_action'])->toBeNull();
});

it('summarises the whole filter, not the page', function (): void {
    document(['uuid' => 'a', 'date' => '2026-03-01', 'amount' => 100.00]);
    document(['uuid' => 'b', 'date' => '2026-01-15', 'amount' => 50.00, 'module' => 'transaction']);
    booking('a', HubDocument::STATUS_FAILED);

    $summary = backlog()->summary([]);

    expect($summary->total)->toBe(2)
        ->and($summary->amountTotal)->toBe(150.0)
        ->and($summary->oldestDate)->toContain('2026-01-15')
        ->and($summary->byStatus[HubDocument::STATUS_FAILED])->toBe(1)
        ->and($summary->byStatus[BacklogStatus::NOT_BOOKED])->toBe(1)
        ->and($summary->byStatus[HubDocument::STATUS_REJECTED])->toBe(0)
        ->and($summary->byModule)->toBe(['invoice' => 1, 'transaction' => 1]);
});

it('reads one current row, so the list and the attached booking cannot disagree', function (): void {
    document(['uuid' => 'inv-1']);
    booking('inv-1', HubDocument::STATUS_POSTED, [
        'type' => 'sales_invoice',
        'external_ref' => 'exact-9',
        'accounting_changed_at' => '2026-08-18T10:00:00+00:00',
        'accounting_change_action' => 'updated',
    ]);
    $later = booking('inv-1', HubDocument::STATUS_FAILED, ['type' => 'credit_note']);

    $rows = backlog()->paginate([])->items();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->status)->toBe(HubDocument::STATUS_POSTED)
        ->and($rows[0]->accounting_change_action)->toBe('updated')
        ->and($rows[0]->hub_document->id)->not->toBe($later->id)
        ->and($rows[0]->hub_document->status)->toBe(HubDocument::STATUS_POSTED);
});

it('treats a wildcard in the search term as a character, not a pattern', function (): void {
    document(['uuid' => 'inv-1', 'party' => 'Acme']);
    document(['uuid' => 'inv-2', 'party' => 'Bravo']);
    document(['uuid' => 'inv-3', 'party' => '100%']);
    document(['uuid' => 'inv-4', 'party' => 'Hola! BV']);

    expect(backlog()->paginate(['search_term' => '%'])->items())->toHaveCount(1)
        ->and(backlog()->paginate(['search_term' => '_'])->items())->toHaveCount(0)
        ->and(backlog()->paginate(['search_term' => '!'])->items())->toHaveCount(1)
        ->and(backlog()->paginate(['search_term' => 'Acme'])->items())->toHaveCount(1);
});

it('reads the backlog off the connection that holds the ledger', function (): void {
    $this->useLedgerDatabase($this->temporaryDatabase());
    $this->createDocumentsTable('tenant');

    document(['uuid' => 'inv-1']);
    booking('inv-1', HubDocument::STATUS_FAILED);

    $rows = backlog()->paginate([])->items();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->status)->toBe(HubDocument::STATUS_FAILED)
        ->and(backlog()->summary([])->total)->toBe(1);
});
