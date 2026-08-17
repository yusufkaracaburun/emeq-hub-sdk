# Inbound Hub webhooks

Spatie `webhook-client` bases live in the SDK; apps only wire tenancy + handlers.
One published file: `config/hub.php`. At boot the package upserts the Hub entry
into Spatie's `webhook-client.configs` from `hub.webhook.*`.

Signing secret comes from `config('hub.webhook.secret')` (`EMEQ_HUB_WEBHOOK_SECRET`).

## Wiring

1. Publish `config/hub.php` (`hub:install` / `--tag=hub-config`) — defaults use
   `HubWebhookProfile` / `ProcessHubWebhookJob`.
2. Bind `ResolvesWebhookAccount` (`account_id` → tenant; may switch DB).
3. `Route::webhooks('webhooks/emeq-hub', 'emeq-hub')` + CSRF except.
4. `php artisan vendor:publish --tag=hub-migrations` then migrate on the webhook DB
   (tenant DB if multi-DB — see below).
5. Multi-DB: set `hub.webhook.job` (and optionally `profile`) in `config/hub.php`
   to your subclass that uses `SerializesHubWebhookByIds`.

## Connection placement

`ProcessHubWebhookJob` binds your account context **first**, then reads
`webhook_calls` — both `resolveWebhookCall()` and the deduplication query run on
whatever connection is default *after* `bindAccountContext()`.

So in a multi-DB app:

- `webhook_calls` must live in the **tenant** DB, not the central one.
- `ResolvesWebhookAccount::prepare()` must switch the connection **before**
  Spatie stores the call, so the row lands in that same tenant DB.

Getting this wrong fails **silently**: `resolveWebhookCall()` returns `null` and
every delivery is logged as `hub.webhook.skipped` / `webhook_call_missing` — no
exception, no failed job, no retry.

## Handlers

Override `handles(): array` to name the `HubWebhookEvent` cases you act on, and
`onEvent()` to act on them — routed there instead of `onIgnored()`, and
`HubWebhookHandled` is dispatched alongside it. `connection.revoked` always
goes to `onConnectionRevoked()` regardless of `handles()`. Override
`onIgnored()` only for events you deliberately do not claim.

```php
class BookkeepingWebhookJob extends ProcessHubWebhookJob
{
    protected function handles(): array
    {
        return [HubWebhookEvent::SALES_INVOICE_CHANGED];
    }

    protected function onEvent(HubWebhookEnvelope $envelope, ?string $eventId, ?string $requestId): void
    {
        // …
    }
}
```

A job that overrides neither method behaves exactly as before: every non-revoked
event falls through to `onIgnored()`.

`HubWebhookEvent` is a backed enum (keep in sync with Hub `CanonicalEvent`);
`$envelope->event` is an enum case, and an event added by Hub after your SDK
release decodes to `HubWebhookEvent::UNMAPPED` rather than throwing.

```php
if ($envelope->event === HubWebhookEvent::CONNECTION_REVOKED) { /* … */ }
$wireValue = $envelope->event->value; // 'connection.revoked'
```

## `caused_by_hub` marks an entity Hub has ever written, not this change

Hub subscribes to bookkeeping topics it also writes to. Book an invoice and the
bookkeeping package reports that change, so the notification travels back to the app
that asked for it — `$envelope->causedByHub` exists to let a consumer recognise that
echo. It does **not** mean what it sounds like.

`causedByHub` is computed from whether a link record exists showing Hub authored
this entity at some point, *ever* — not whether Hub wrote *this particular* change.
Once Hub has booked a document, every later notification for that same document
carries `caused_by_hub: true`, including a bookkeeper hand-editing it in the
provider's own UI weeks afterwards. That edit is exactly the kind of external
change a consumer needs to see, and the current flag hides it.

```php
if ($envelope->causedByHub) {
    // Only tells you Hub wrote this entity at some point — not that Hub wrote
    // *this* change. Do not use this to decide whether to act on the event.
}
```

There is currently no reliable way to tell your own write's echo apart from a
later human correction on the same entity from the SDK alone. A Hub-side fix
is proposed — a `hub_last_wrote_at` timestamp per entity, so a consumer could
compare it against the delivery's `occurred_at` — but that field does not exist
yet, and this SDK makes no assumption about its shape until it ships.

## Deduplication

Deduplication takes a cache lock per account + `X-Emeq-Event-Id` so concurrent
redeliveries cannot both process, while one event id delivered to two accounts
still processes twice. That store must support atomic locks: Laravel's `database`
default needs the framework's `cache_locks` table, or point
`EMEQ_HUB_WEBHOOK_LOCK_STORE` at redis/memcached. A failed job records its
exception on `webhook_calls`, so Hub's redelivery is not mistaken for a duplicate.

Some event ids identify nothing: Hub sends the literal `no-id` when the partner
omitted an id of its own, so unrelated events share it. Those are processed like
a webhook with no event id at all — never deduplicated. To recognise more, pass
them to `HubWebhookDeduplicator`'s third constructor argument from
`ProcessHubWebhookJob::deduplicator()`.

The published `webhook_calls` migration carries a `['name', 'id']` index. It pays
off when the table carries more than one webhook config (Hub alongside Stripe,
Mollie, …); on a Hub-only table MySQL ignores it. If you migrated before 0.9.0
and share `webhook_calls` across configs, add it yourself — see the changelog.
