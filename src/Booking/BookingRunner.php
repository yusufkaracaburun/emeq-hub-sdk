<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Booking;

use Closure;
use Emeq\HubSdk\Booking\Contracts\ResolvesBookableDocument;
use Emeq\HubSdk\Events\DocumentBooked;
use Emeq\HubSdk\Events\DocumentBookingFailed;
use Emeq\HubSdk\Exceptions\BookingAlreadyInProgress;
use Emeq\HubSdk\Exceptions\BookingTemporarilyUnavailable;
use Emeq\HubSdk\Exceptions\DocumentNotAuthorized;
use Emeq\HubSdk\Exceptions\DocumentNotBookable;
use Emeq\HubSdk\Exceptions\HubException;
use Emeq\HubSdk\Exceptions\RateLimitException;
use Emeq\HubSdk\Exceptions\ServerException;
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
    public function book(array $requested, bool $withAttachment = true): array
    {
        return $this->withinBudget(
            $requested,
            fn (string $module, string $id): BatchBookingResult => new BatchBookingResult(
                $module,
                $id,
                $this->bookOne($module, $id, $withAttachment),
            ),
        );
    }

    public function checkOne(string $module, string $id): CheckOutcome
    {
        try {
            $document = $this->documents->resolve($module, $id);
        } catch (ModelNotFoundException) {
            return new CheckOutcome($module, $id, 404, null, BookingMessages::line('not_found'));
        } catch (DocumentNotAuthorized) {
            return new CheckOutcome($module, $id, 403, null, BookingMessages::line('not_allowed'));
        } catch (DocumentNotBookable $e) {
            return new CheckOutcome($module, $id, 422, null, $e->getMessage());
        }

        try {
            return new CheckOutcome($module, $id, 200, $this->hub->accounting()->validateDocument($document->document), null);
        } catch (RateLimitException|ServerException $e) {
            // Throttled or upstream is down: repeating this check is exactly the
            // right move, so it must not land on 502 with the answers that are
            // nobody's to act on. Same split {@see bookOne()} already makes.
            return new CheckOutcome($module, $id, 503, null, BookingMessages::line('temporarily_unavailable'), $e->retryAfter);
        } catch (HubException $e) {
            // Hub could not answer. Says nothing about the document, so it is
            // not a 422 the user can act on.
            return new CheckOutcome($module, $id, 502, null, $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return new CheckOutcome($module, $id, 503, null, BookingMessages::line('temporarily_unavailable'));
        }
    }

    public function bookOne(string $module, string $id, bool $withAttachment = true): BookingOutcome
    {
        try {
            $document = $this->documents->resolve($module, $id);
        } catch (ModelNotFoundException) {
            return $this->announce($module, $id, BookingOutcome::notFound());
        } catch (DocumentNotAuthorized) {
            return $this->announce($module, $id, BookingOutcome::notAllowed());
        } catch (DocumentNotBookable $e) {
            return $this->announce($module, $id, BookingOutcome::refused($e->getMessage()));
        }

        try {
            $record = $this->booker->book(
                $document->document,
                $withAttachment ? $document->attachments : null,
            );
        } catch (BookingAlreadyInProgress $e) {
            return $this->announce($module, $id, BookingOutcome::alreadyInProgress($e->getMessage(), $e->retryAfter), $document->subject);
        } catch (BookingTemporarilyUnavailable $e) {
            return $this->announce($module, $id, BookingOutcome::unavailable($e->getMessage(), $e->retryAfter), $document->subject);
        } catch (HubException $e) {
            return $this->announce($module, $id, BookingOutcome::upstreamFailure($e->getMessage()), $document->subject);
        }

        return $this->announce($module, $id, BookingOutcome::from($record), $document->subject);
    }

    /**
     * Says what happened, and hands the outcome back so a caller reads as one
     * expression.
     *
     * Fired here rather than in {@see DocumentBooker} because module and id are
     * what a consumer logs against, and the booker is given a payload without
     * either. A batch runs through {@see bookOne()}, so it reports per document
     * and needs nothing of its own.
     */
    protected function announce(string $module, string $id, BookingOutcome $outcome, mixed $subject = null): BookingOutcome
    {
        event($outcome->booked
            ? new DocumentBooked($module, $id, $outcome, $subject)
            : new DocumentBookingFailed($module, $id, $outcome, $subject));

        return $outcome;
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
