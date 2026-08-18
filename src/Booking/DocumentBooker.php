<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Booking;

use Closure;
use Emeq\HubSdk\Contracts\ResolvesAccountId;
use Emeq\HubSdk\Exceptions\BookingAlreadyInProgress;
use Emeq\HubSdk\Exceptions\BookingTemporarilyUnavailable;
use Emeq\HubSdk\Exceptions\DocumentNotBookable;
use Emeq\HubSdk\Exceptions\HubException;
use Emeq\HubSdk\Exceptions\MissingConfigurationException;
use Emeq\HubSdk\Exceptions\RateLimitException;
use Emeq\HubSdk\Exceptions\ServerException;
use Emeq\HubSdk\Hub;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Throwable;

/**
 * Books one canonical document into the connected bookkeeping and records what
 * happened in the ledger.
 *
 * Takes a canonical document array, not a model: what a sales invoice looks
 * like is the consumer's business, what Hub does with it is this package's.
 * Consumers map first — refusing what cannot be mapped with
 * {@see DocumentNotBookable} — and call this with the result.
 *
 * Every decided answer becomes a row; every undecided one throws
 * {@see BookingTemporarilyUnavailable} and leaves the ledger untouched. That
 * distinction is the whole retry policy — "no row" and "a row saying failed"
 * mean different things to the next run.
 */
class DocumentBooker
{
    /**
     * Errors that say nothing about the document itself. Retrying with the same
     * idempotency key is safe and is the only correct response.
     *
     * Hub guards a booking twice over and each guard has its own word for "wait":
     * `idempotency_request_in_progress` from the Idempotency-Key claim, and
     * `document_sync_in_progress` from the per-connection claim that outlives it.
     * Both are 409s that mean the same thing, and treating either as a failure
     * would write a permanent no into the ledger for a document nobody refused.
     */
    protected const TRANSIENT_ERRORS = ['idempotency_request_in_progress', 'document_sync_in_progress'];

    /**
     * Answers about this document that will not change on a retry.
     */
    protected const REJECTIONS = ['document_already_posted', 'idempotency_key_reuse', 'upstream_rejected'];

    /**
     * The failure classes that describe the document rather than its
     * surroundings: a conflict with something already booked, or a functional
     * refusal by the bookkeeping. A missing mapping or a revoked ability is
     * neither — those the consumer can fix and send again.
     *
     * Only reachable for a decided answer: `retryable` outcomes leave before
     * this, and Hub's 5xx becomes a {@see ServerException} earlier still, so the
     * `PROVIDER_ERROR` that arrives here is always the 422 kind.
     *
     * The distinction is what the backlog shows, not whether anything resends —
     * `rejected` and `failed` are both final.
     */
    protected const REJECTED_CATEGORIES = ['CONFLICT', 'PROVIDER_ERROR'];

    public function __construct(
        protected readonly Hub $hub,
        protected readonly ResolvesAccountId $account,
    ) {}

    /**
     * @param  array<string, mixed>  $document  canonical Hub document, carrying `type` and `external_id`
     * @param  (Closure(): list<array<string, mixed>>)|null  $attachments  rendered lazily, inside the ledger's
     *                                                                     error handling: a renderer that throws
     *                                                                     records `attachment_render_failed`
     *                                                                     instead of losing the attempt
     *
     * @throws BookingTemporarilyUnavailable when nothing was decided and the caller should retry
     */
    public function book(array $document, ?Closure $attachments = null): HubDocument
    {
        $externalId = $this->externalId($document);
        $this->documentType($document);

        // Fail fast rather than wait: a second concurrent attempt for the same
        // document is the same "try again" situation as an in-flight Hub-side
        // idempotency conflict, and holding a request worker for up to the
        // connector's own timeout would only trade one problem for another.
        $result = $this->lock($externalId)
            ->get(fn (): HubDocument => $this->attemptBooking($document, $externalId, $attachments));

        if (! $result instanceof HubDocument) {
            throw new BookingAlreadyInProgress(
                "Another booking attempt for {$externalId} is already in progress."
            );
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  (Closure(): list<array<string, mixed>>)|null  $attachments
     */
    protected function attemptBooking(
        array $document,
        string $externalId,
        ?Closure $attachments,
    ): HubDocument {
        $record = HubDocument::forBooking($externalId, $this->account->accountId());

        // Already in the bookkeeping. Sending it again cannot improve on that:
        // identical content is a no-op and changed content is refused, which
        // would demote a posted row to rejected. A correction is a credit note.
        if ($record->exists && $record->status === HubDocument::STATUS_POSTED) {
            return $record;
        }

        if ($record->party_external_id !== null) {
            $party = $this->party($document);
            $party['external_id'] = $record->party_external_id;
            $document['party'] = $party;
        }

        if ($attachments !== null) {
            try {
                $rendered = $attachments();
            } catch (Throwable $e) {
                report($e);

                // No trace: this failed here, before Hub was asked anything. A
                // request id left over from an earlier attempt would point at a
                // Hub log line that has nothing to do with this outcome.
                return $this->store($record, $document, [
                    'status' => HubDocument::STATUS_FAILED,
                    'error' => 'attachment_render_failed',
                    'error_message' => $e->getMessage(),
                    'request_id' => null,
                    'category' => null,
                ]);
            }

            if ($rendered !== []) {
                $document['attachments'] = $rendered;
            }
        }

        try {
            $result = $this->hub->accounting()->createDocument($document, $externalId);
        } catch (RateLimitException|ServerException $e) {
            throw new BookingTemporarilyUnavailable($e->getMessage(), $e->retryAfter, $e);
        } catch (HubException $e) {
            if ($this->decidesNothing($e)) {
                throw new BookingAlreadyInProgress($e->getMessage(), $e->retryAfter, $e);
            }

            $isRejection = $this->isRejection($e);

            if (! $isRejection) {
                report($e);
            }

            return $this->store($record, $document, [
                'status' => $isRejection ? HubDocument::STATUS_REJECTED : HubDocument::STATUS_FAILED,
                'error' => $e->error,
                'error_message' => $e->getMessage(),
                'request_id' => $e->requestId,
                'category' => $e->category,
            ]);
        } catch (Throwable $e) {
            report($e);

            // Not a HubException: a connect/read timeout (Saloon's
            // FatalRequestException) or another transport failure. Hub may
            // have received and posted the document anyway — record that the
            // outcome is unknown instead of leaving no trace at all.
            //
            // Nothing retries this on its own, because only the caller knows the
            // document still says what it said. Offering it again unchanged is
            // safe and is how an unknown row is resolved: see {@see book()}.
            return $this->store($record, $document, [
                'status' => HubDocument::STATUS_UNKNOWN,
                'error' => 'connection_interrupted',
                'error_message' => $e->getMessage(),
                'request_id' => null,
                'category' => null,
            ]);
        }

        $record = $this->store($record, $document, [
            'status' => HubDocument::STATUS_POSTED,
            'external_ref' => $result['external_ref'] ?? null,
            'external_number' => $result['external_number'] ?? null,
            'booked_at' => Carbon::now(),
            'error' => null,
            'error_message' => null,
            'request_id' => null,
            'category' => null,
        ]);

        // Not a column: only this attempt can report what Hub did to the
        // relation, so it rides the record rather than the ledger row.
        $record->warnings = self::parseWarnings($result['warnings'] ?? null);

        return $record;
    }

    /**
     * Hub's own report of what it did to the relation while booking. Untrusted
     * JSON: a malformed entry is dropped rather than passed through as-is.
     *
     * @return list<array<string, mixed>>
     */
    private static function parseWarnings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $warnings = [];

        foreach ($value as $warning) {
            if (! is_array($warning) || ! is_string($warning['code'] ?? null) || ! is_string($warning['message'] ?? null)) {
                continue;
            }

            $context = $warning['context'] ?? [];

            $warnings[] = [
                'code' => $warning['code'],
                'message' => $warning['message'],
                'context' => is_array($context) ? $context : [],
            ];
        }

        return $warnings;
    }

    /**
     * Whether Hub answered without deciding anything about this document, so the
     * same request may go out again.
     *
     * Hub says so itself through `retryable`. The code list behind it is the
     * fallback for a Hub that predates the field — and the reason the field
     * exists: a list here ages the moment Hub adds a code, silently, in the
     * direction of writing a permanent failure for a document nobody refused.
     */
    protected function decidesNothing(HubException $e): bool
    {
        return $e->retryable ?? in_array($e->error, static::TRANSIENT_ERRORS, true);
    }

    /**
     * Whether this answer is about the document itself.
     *
     * Derived from the envelope's category so a code this release has never
     * heard of still lands in the right column; the list is the fallback for a
     * Hub that sends no `retryable`, and therefore no opinion at all.
     */
    protected function isRejection(HubException $e): bool
    {
        if ($e->retryable === null) {
            return in_array($e->error, static::REJECTIONS, true);
        }

        return in_array($e->category, static::REJECTED_CATEGORIES, true);
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $outcome
     */
    protected function store(HubDocument $record, array $document, array $outcome): HubDocument
    {
        $record->fill(HubDocument::withoutMissingTrace($outcome));

        // Pin the relation key the first booking used: unlinking a party from
        // its parent record would otherwise send a different key and open a
        // second relation in the bookkeeping.
        $partyExternalId = $this->party($document)['external_id'] ?? null;
        $record->party_external_id ??= is_scalar($partyExternalId) ? (string) $partyExternalId : null;

        $record->account_id = $this->account->accountId();
        $record->type = $this->documentType($document);
        $record->external_id = $this->externalId($document);

        try {
            $record->save();
        } catch (UniqueConstraintViolationException) {
            return $this->mergeWithWinner($record);
        }

        return $record;
    }

    /**
     * Two attempts decided the same document and the ledger's identity index let
     * only one of them in.
     *
     * Reachable whenever the booking lock stops covering the send — an expired
     * lease, a flushed cache store — after which both attempts read "no row yet"
     * and both insert. Hub still refused to book it twice; what is left here is
     * to keep the better of the two answers, and `posted` is always the better
     * one. Demoting it would hide a document that is in the bookkeeping.
     */
    protected function mergeWithWinner(HubDocument $mine): HubDocument
    {
        $winner = HubDocument::query()
            ->where('account_id', $mine->account_id)
            ->where('type', $mine->type)
            ->where('external_id', $mine->external_id)
            ->firstOrFail();

        if ($mine->status !== HubDocument::STATUS_POSTED || $winner->status === HubDocument::STATUS_POSTED) {
            return $winner;
        }

        $winner->fill(HubDocument::withoutMissingTrace([
            'status' => HubDocument::STATUS_POSTED,
            'external_ref' => $mine->external_ref,
            'external_number' => $mine->external_number,
            'booked_at' => $mine->booked_at,
            'error' => null,
            'error_message' => null,
            'request_id' => null,
            'category' => null,
        ]))->save();

        return $winner;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    protected function party(array $document): array
    {
        $party = $document['party'] ?? null;

        return is_array($party) ? $party : [];
    }

    /**
     * Half of the ledger's identity, alongside the external id. An empty type
     * would collapse two different documents onto one row.
     *
     * @param  array<string, mixed>  $document
     */
    protected function documentType(array $document): string
    {
        $type = $document['type'] ?? null;

        if (! is_scalar($type) || (string) $type === '') {
            throw new InvalidArgumentException('A canonical document needs a non-empty type; it is half of the ledger key.');
        }

        return (string) $type;
    }

    /**
     * The document's identity and its idempotency key in one: the same document
     * retried after a timeout has to present the same key, or Hub books it a
     * second time.
     *
     * @param  array<string, mixed>  $document
     */
    protected function externalId(array $document): string
    {
        $externalId = $document['external_id'] ?? null;

        if (! is_scalar($externalId) || (string) $externalId === '') {
            throw new InvalidArgumentException('A canonical document needs a non-empty external_id; it is the ledger key and the idempotency key.');
        }

        return (string) $externalId;
    }

    /**
     * The store named by `hub.booking.lock_store`, or the default one. Either
     * has to support atomic locks — Laravel's `database` driver only does once
     * the framework's cache_locks table exists, and a store that cannot lock
     * has to say so here rather than let two attempts post the same document.
     */
    protected function lock(string $externalId): Lock
    {
        // Config::string() would throw on the null this key legitimately holds.
        $configured = Config::get('hub.booking.lock_store');
        $name = is_string($configured) && $configured !== '' ? $configured : null;

        $store = Cache::store($name)->getStore();

        if (! $store instanceof LockProvider) {
            $default = Config::get('cache.default');

            throw MissingConfigurationException::bookingLockStoreNotLockable(
                $name ?? (is_string($default) ? $default : 'default'),
            );
        }

        return $store->lock($this->lockKey($externalId), $this->lockSeconds());
    }

    /**
     * The lock has to outlive the send it protects. Once it expires early, a
     * second attempt starts while the first is still on the wire, and the two
     * race to write the same ledger identity — {@see mergeWithWinner()} exists
     * to survive that, not to make it acceptable.
     *
     * Refused at the point of use rather than at boot: a consumer that never
     * books should not be stopped by a booking setting.
     */
    protected function lockSeconds(): int
    {
        $configured = Config::get('hub.booking.lock_seconds');
        $seconds = is_numeric($configured) ? (int) $configured : 40;

        $timeout = Config::get('hub.timeout');
        $timeout = is_numeric($timeout) ? (int) $timeout : 30;

        if ($seconds <= $timeout) {
            throw MissingConfigurationException::bookingLockShorterThanTimeout($seconds, $timeout);
        }

        return $seconds;
    }

    /**
     * Scoped to (account, external_id), not type: the same document can be
     * computed as sales_invoice or credit_note depending on its live total,
     * and both attempts must serialize against the same identity.
     */
    protected function lockKey(string $externalId): string
    {
        return sprintf('hub-document-booking:%s:%s', $this->account->accountId(), $externalId);
    }
}
