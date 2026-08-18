<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Booking;

use Emeq\HubSdk\Backlog\PostedDocuments;
use Emeq\HubSdk\Events\HubWebhookReceived;
use Emeq\HubSdk\Webhooks\HubWebhookAction;
use Emeq\HubSdk\Webhooks\HubWebhookEnvelope;
use Emeq\HubSdk\Webhooks\HubWebhookEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Marks a ledger row when the bookkeeping changed a document this consumer
 * booked itself.
 *
 * The columns it writes are the ones {@see BacklogRepository}'s
 * `accounting_changed` filter reads and {@see PostedDocuments}
 * keeps out of the "already booked" exclusion. They shipped without a writer,
 * which left every consumer transcribing the same account-scoped ledger join out
 * of `docs/webhooks.md` — the same reason `PostedDocuments` ships here rather
 * than in each app: getting the account scope wrong is silent, and reads as
 * "nothing changed" forever.
 *
 * The join key is the provider's own entity id. Hub answers a booking with
 * `external_ref`, and reports the same id on the change event as `entity_id`.
 */
class AccountingChangeRecorder
{
    /**
     * Whether this ledger carries the columns to mark. Resolved once: a schema
     * read per delivery would tax the pipe for an answer that cannot change
     * while the process runs, and a consumer that books nothing has no table at
     * all — a throw there would fail the delivery and earn five Hub retries.
     */
    protected static ?bool $marksChanges = null;

    /**
     * @param  int  $echoWindowSeconds  how long after a write a change still counts as its echo
     */
    public function __construct(protected readonly int $echoWindowSeconds = 300) {}

    /**
     * The events that can name a document this consumer booked. An event about
     * anything else finds no row and stops there, so the list is a filter for
     * work not done rather than a correctness boundary.
     *
     * @return list<HubWebhookEvent>
     */
    public static function events(): array
    {
        return [
            HubWebhookEvent::SALES_INVOICE_CHANGED,
            HubWebhookEvent::PURCHASE_INVOICE_CHANGED,
            HubWebhookEvent::DOCUMENT_CHANGED,
        ];
    }

    /**
     * Listener entry point. Registered on {@see HubWebhookReceived} by the
     * service provider, which fires for every accepted envelope after the
     * account context is bound — so the ledger read lands on the right database.
     */
    public function handle(HubWebhookReceived $event): void
    {
        if (! in_array($event->envelope->event, static::events(), true)) {
            return;
        }

        $this->record($event->envelope, $event->eventId);
    }

    /**
     * Never throws: a marker is worth less than the delivery carrying it. A
     * failure here would return a 5xx to Hub, which retries the whole envelope
     * five times over roughly three hours for a column nobody is waiting on.
     */
    public function record(HubWebhookEnvelope $envelope, ?string $eventId): void
    {
        try {
            $this->mark($envelope, $eventId);
        } catch (Throwable $e) {
            report($e);
        }
    }

    protected function mark(HubWebhookEnvelope $envelope, ?string $eventId): void
    {
        $key = $this->entityKey($envelope);

        if ($key === null || ! static::marksChanges()) {
            return;
        }

        $document = HubDocument::query()
            ->where('account_id', $envelope->accountId)
            ->where('external_ref', $key)
            ->where('status', HubDocument::STATUS_POSTED)
            ->orderByDesc('id')
            ->first();

        if (! $document instanceof HubDocument || $this->echoesOurOwnWrite($envelope, $document)) {
            return;
        }

        HubDocument::query()
            ->whereKey($document->getKey())
            ->update([
                'accounting_changed_at' => $this->parse($envelope->occurredAt) ?? now(),
                'accounting_change_action' => $this->action($envelope),
                'accounting_change_event_id' => $eventId,
            ]);

        Log::info('hub.webhook.accounting_change', [
            'event' => $envelope->event->value,
            'account_id' => $envelope->accountId,
            'hub_document_id' => $document->getKey(),
            'external_id' => $document->external_id,
            'external_ref' => $key,
            'action' => $this->action($envelope),
            'event_id' => $eventId,
        ]);
    }

    /**
     * The document this event is about.
     *
     * Reads the id Hub normalised onto the envelope and nothing else: a raw
     * payload is partner-shaped, and reaching into it here would put a provider
     * name in a package that must not carry one (ADR-0001). A provider Hub
     * cannot map an id for reports none, and a consumer that wants to rescue
     * that case overrides this method.
     */
    protected function entityKey(HubWebhookEnvelope $envelope): ?string
    {
        return $envelope->entityId !== null && $envelope->entityId !== ''
            ? $envelope->entityId
            : null;
    }

    /**
     * The canonical vocabulary, so the column does not end up holding 'Update',
     * 'updated' and '2' for the same thing. An action Hub could not map is
     * recorded as nothing rather than as a partner's own spelling.
     */
    protected function action(HubWebhookEnvelope $envelope): ?string
    {
        return $envelope->action !== null && $envelope->action !== HubWebhookAction::UNMAPPED
            ? $envelope->action->value
            : null;
    }

    /**
     * Posting a document makes the provider fire a change event about this
     * consumer's own write, seconds later. Marking that as "the bookkeeping
     * changed this" puts a badge on every document it books.
     *
     * Two signals, and either one suppressing is deliberate. Hub reports when it
     * last wrote the entity, which is the write itself and so the better of the
     * two — but it goes quiet in more ways than "a human did this": no recorded
     * write, an unparseable timestamp, or a partner whose notification is
     * stamped a second before Hub finished writing. {@see HubWebhookEnvelope::isOwnEcho()}
     * reads all of those as "not an echo", which is right for deciding whether
     * to look at an event and wrong for a marker that would then appear on
     * everything.
     *
     * Proximity to this consumer's own booking covers that. It is cruder and it
     * can suppress a genuine correction made within the window, which is the
     * cheaper mistake: a change that goes unmarked still shows in the
     * bookkeeping, a marker that cries wolf teaches people to ignore the one
     * that matters. Widen or narrow it through the constructor.
     */
    protected function echoesOurOwnWrite(HubWebhookEnvelope $envelope, HubDocument $document): bool
    {
        if ($envelope->isOwnEcho($this->echoWindowSeconds)) {
            return true;
        }

        if ($document->booked_at === null) {
            return false;
        }

        $happenedAt = $this->parse($envelope->occurredAt) ?? now();

        return $happenedAt->lt($document->booked_at->copy()->addSeconds($this->echoWindowSeconds));
    }

    protected function parse(?string $timestamp): ?Carbon
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        try {
            return Carbon::parse($timestamp);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether this ledger carries the columns this class writes. A consumer that
     * books nothing never published the migration, and one on a stub from before
     * 0.18 has the table without these columns.
     */
    public static function marksChanges(): bool
    {
        $model = new HubDocument;

        return static::$marksChanges ??= Schema::connection($model->getConnectionName())
            ->hasColumns($model->getTable(), [
                'accounting_changed_at',
                'accounting_change_action',
                'accounting_change_event_id',
            ]);
    }

    /**
     * Drops the cached schema answer. For tests that add or remove the columns
     * within one process; nothing in an application changes its own schema.
     */
    public static function forgetChangeSupport(): void
    {
        static::$marksChanges = null;
    }
}
