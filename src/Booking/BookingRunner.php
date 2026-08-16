<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Booking;

use Closure;
use Emeq\HubSdk\Booking\Contracts\ResolvesBookableDocument;
use Emeq\HubSdk\Exceptions\BookingTemporarilyUnavailable;
use Emeq\HubSdk\Exceptions\DocumentNotAuthorized;
use Emeq\HubSdk\Exceptions\DocumentNotBookable;
use Emeq\HubSdk\Exceptions\HubException;
use Emeq\HubSdk\Hub;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Checks and books documents by (module, id) — one, or a batch.
 *
 * Owns the table nobody wants to rediscover: which failure becomes which
 * answer. Missing is 404, unauthorised is 403, unmappable is a refusal, an
 * undecided send is 503, and anything unexpected is reported rather than
 * shown raw.
 *
 * A batch stops once its time budget is spent, so a run cannot outlive the
 * request that started it. Callers get fewer results than they asked for and
 * repeat with the remainder — which is safe precisely because
 * {@see DocumentBooker} refuses to send an already-posted document.
 */
class BookingRunner
{
    public function __construct(
        protected readonly ResolvesBookableDocument $documents,
        protected readonly DocumentBooker $booker,
        protected readonly Hub $hub,
    ) {}

    /**
     * @param  list<array{module: string, id: string}>  $requested
     * @return list<CheckOutcome>
     */
    public function check(array $requested): array
    {
        return $this->withinBudget(
            $requested,
            fn (string $module, string $id): CheckOutcome => $this->checkOne($module, $id),
        );
    }

    /**
     * @param  list<array{module: string, id: string}>  $requested
     * @return list<BatchBookingResult>
     */
    public function book(array $requested, bool $createRelation = false, bool $withAttachment = true): array
    {
        return $this->withinBudget(
            $requested,
            fn (string $module, string $id): BatchBookingResult => new BatchBookingResult(
                $module,
                $id,
                $this->bookOne($module, $id, $createRelation, $withAttachment),
            ),
        );
    }

    public function checkOne(string $module, string $id): CheckOutcome
    {
        try {
            $document = $this->documents->resolve($module, $id);
        } catch (ModelNotFoundException) {
            return new CheckOutcome($module, $id, null, BookingMessages::line('not_found'));
        } catch (DocumentNotAuthorized) {
            return new CheckOutcome($module, $id, null, BookingMessages::line('not_allowed'));
        } catch (DocumentNotBookable $e) {
            return new CheckOutcome($module, $id, null, $e->getMessage());
        }

        try {
            return new CheckOutcome($module, $id, $this->hub->accounting()->validateDocument($document->document), null);
        } catch (HubException $e) {
            return new CheckOutcome($module, $id, null, $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return new CheckOutcome($module, $id, null, BookingMessages::line('temporarily_unavailable'), retryable: true);
        }
    }

    public function bookOne(string $module, string $id, bool $createRelation = false, bool $withAttachment = true): BookingOutcome
    {
        try {
            $document = $this->documents->resolve($module, $id);
        } catch (ModelNotFoundException) {
            return BookingOutcome::notFound();
        } catch (DocumentNotAuthorized) {
            return BookingOutcome::notAllowed();
        } catch (DocumentNotBookable $e) {
            return BookingOutcome::refused($e->getMessage());
        }

        try {
            $record = $this->booker->book(
                $document->document,
                $withAttachment ? $document->attachments : null,
                $createRelation,
            );
        } catch (BookingTemporarilyUnavailable) {
            return BookingOutcome::unavailable();
        } catch (HubException $e) {
            return BookingOutcome::upstreamFailure($e->getMessage());
        }

        return BookingOutcome::from($record);
    }

    /**
     * @template TResult
     *
     * @param  list<array{module: string, id: string}>  $requested
     * @param  Closure(string, string): TResult  $handle
     * @return list<TResult>
     */
    protected function withinBudget(array $requested, Closure $handle): array
    {
        $deadline = microtime(true) + $this->timeBudget();

        $results = [];

        foreach ($requested as $document) {
            $results[] = $handle($document['module'], $document['id']);

            if (microtime(true) >= $deadline) {
                break;
            }
        }

        return $results;
    }

    /**
     * Checked after each document, never before: one document always runs, so a
     * caller cannot get an empty answer it has no way to make progress on.
     */
    protected function timeBudget(): float
    {
        $seconds = Config::get('hub.booking.batch_seconds');

        return is_numeric($seconds) ? (float) $seconds : 60.0;
    }
}
