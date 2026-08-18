<?php

declare(strict_types=1);

namespace Emeq\HubSdk\Booking;

use Emeq\HubSdk\Events\HubWebhookReceived;
use Emeq\HubSdk\Webhooks\HubWebhookAction;
use Emeq\HubSdk\Webhooks\HubWebhookEnvelope;
use Emeq\HubSdk\Webhooks\HubWebhookEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AccountingChangeRecorder
{
    protected static ?bool $marksChanges = null;

    /** @param  int  $echoWindowSeconds  how long after a write a change still counts as its echo */
    public function __construct(protected readonly int $echoWindowSeconds = 300) {}

    /** @return list<HubWebhookEvent> */
    public static function events(): array
    {
        return [
            HubWebhookEvent::SALES_INVOICE_CHANGED,
            HubWebhookEvent::PURCHASE_INVOICE_CHANGED,
            HubWebhookEvent::DOCUMENT_CHANGED,
        ];
    }

    public function handle(HubWebhookReceived $event): void
    {
        if (! in_array($event->envelope->event, static::events(), true)) {
            return;
        }

        $this->record($event->envelope, $event->eventId);
    }

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

    protected function entityKey(HubWebhookEnvelope $envelope): ?string
    {
        return $envelope->entityId !== null && $envelope->entityId !== ''
            ? $envelope->entityId
            : null;
    }

    protected function action(HubWebhookEnvelope $envelope): ?string
    {
        return $envelope->action !== null && $envelope->action !== HubWebhookAction::UNMAPPED
            ? $envelope->action->value
            : null;
    }

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

    public static function forgetChangeSupport(): void
    {
        static::$marksChanges = null;
    }
}
