<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Booking;

use Closure;
use Emeq\HubSdk\Contracts\ResolvesAccountId;
use Emeq\HubSdk\Exceptions\BookingAlreadyInProgress;
use Emeq\HubSdk\Exceptions\BookingTemporarilyUnavailable;
use Emeq\HubSdk\Exceptions\HubException;
use Emeq\HubSdk\Exceptions\RateLimitException;
use Emeq\HubSdk\Exceptions\ServerException;
use Emeq\HubSdk\Hub;
use Emeq\HubSdk\Support\HubLocks;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

class DocumentBooker
{
    protected const TRANSIENT_ERRORS = ['idempotency_request_in_progress', 'document_sync_in_progress'];

    protected const REJECTIONS = ['document_already_posted', 'idempotency_key_reuse', 'upstream_rejected'];

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

        if ($record->exists && $record->status === HubDocument::STATUS_POSTED && ! $record->wasDeletedFromAccounting()) {
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
            $result = $this->hub->accounting()->createDocument($document, $this->idempotencyKey($record, $externalId));
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
            ...HubDocument::clearedAccountingChange(),
        ]);

        $record->warnings = self::parseWarnings($result['warnings'] ?? null);

        return $record;
    }

    /** @return list<array<string, mixed>> */
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

    protected function decidesNothing(HubException $e): bool
    {
        return $e->retryable ?? in_array($e->error, static::TRANSIENT_ERRORS, true);
    }

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
            ...HubDocument::clearedAccountingChange(),
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

    /** @param  array<string, mixed>  $document */
    protected function documentType(array $document): string
    {
        $type = $document['type'] ?? null;

        if (! is_scalar($type) || (string) $type === '') {
            throw new InvalidArgumentException('A canonical document needs a non-empty type; it is half of the ledger key.');
        }

        return (string) $type;
    }

    /** @param  array<string, mixed>  $document */
    protected function externalId(array $document): string
    {
        $externalId = $document['external_id'] ?? null;

        if (! is_scalar($externalId) || (string) $externalId === '') {
            throw new InvalidArgumentException('A canonical document needs a non-empty external_id; it is the ledger key and the idempotency key.');
        }

        return (string) $externalId;
    }

    /**
     * A document the bookkeeping deleted travels under a key of its own, or Hub
     * replays the answer it gave the first send and nothing reaches the
     * bookkeeping. The key is derived from the deletion, so a retry of the same
     * re-send still carries the key that first attempt used.
     */
    protected function idempotencyKey(HubDocument $record, string $externalId): string
    {
        if (! $record->wasDeletedFromAccounting()) {
            return $externalId;
        }

        $deletion = $record->accounting_change_event_id
            ?? (string) $record->accounting_changed_at?->getTimestamp();

        return $deletion === '' ? $externalId : $externalId.':'.$deletion;
    }

    protected function lock(string $externalId): Lock
    {
        return HubLocks::bookingStore()->lock($this->lockKey($externalId), $this->lockSeconds());
    }

    protected function lockSeconds(): int
    {
        return HubLocks::bookingSeconds();
    }

    protected function lockKey(string $externalId): string
    {
        return sprintf('hub-document-booking:%s:%s', $this->account->accountId(), $externalId);
    }
}
