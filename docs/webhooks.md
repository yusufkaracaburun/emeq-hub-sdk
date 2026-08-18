# Inbound Hub webhooks

Spatie `webhook-client` bases live in the SDK; apps only wire tenancy + handlers.
One published file: `config/hub.php`. At boot the package upserts the Hub entry
into Spatie's `webhook-client.configs` from `hub.webhook.*`.

Signing secret comes from `config('hub.webhook.secret')` (`EMEQ_HUB_WEBHOOK_SECRET`).

## Wiring

1. Publish `config/hub.php` (`hub:install` / `--tag=hub-config`) — defaults use
   `HubWebhookProfile` / `ProcessHubWebhookJob`.
2. Bind `ResolvesWebhookAccount` (`account_id` → tenant; may switch DB).
3. `Route::webhooks('webhooks/emeq-hub', 'emeq-hub')` + CSRF except. Add
   `->middleware(HubWebhooksEnabled::class)` if you want to be able to shut the
   endpoint — see [Closing the endpoint](#closing-the-endpoint).
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

## Finding your own record: `entity_id` and `action`

`$envelope->entityId` is the id the bookkeeping package itself gave the changed
entity — the same id Hub returned when you booked it. That makes it the join key
between an incoming change and your own ledger row, without parsing a single
provider-specific field out of `data`.

`$envelope->action` is a `HubWebhookAction` — `CREATED`, `UPDATED`, `DELETED`, or
`UNMAPPED` for an action a later Hub release names and this one does not. It says
what happened; `$envelope->event` says to what kind of thing.

Both are nullable, and `null` means "the provider does not tell us", never "no".
Exact carries both. Mollie's notification is a bare resource id, so there is no
action. Snelstart carries an action but no entity id this SDK can read.

```php
$row = HubDocument::query()
    ->where('account_id', $envelope->accountId)
    ->where('external_ref', $envelope->entityId)
    ->first();
```

For the one case that join is nearly always written for — marking a booked
document the bookkeeping changed afterwards — you do not have to write it at
all. `AccountingChangeRecorder` runs it as a listener on `HubWebhookReceived`,
including the echo check below, and fills the `accounting_change_*` columns the
backlog reads. See *The backlog* in the README.

## Telling your own echo apart from a human edit

Hub subscribes to bookkeeping topics it also writes to. Book an invoice and the
bookkeeping package reports that change straight back to you. Acting on that echo
— writing again — is a loop.

Two fields separate the echo from a real change. `$envelope->hubAuthored` says Hub
has written this entity *at some point ever*; `$envelope->hubLastWroteAt` says when
it last did. Only the pair is usable: a delivery seconds after Hub's own write is
almost certainly the echo, while one a week later is a bookkeeper correcting your
document by hand — exactly the change you need to see.

Hub deliberately does not draw that line for you; it reports the two facts and
leaves the window to the consumer. `isOwnEcho()` applies a sane one:

```php
if ($envelope->isOwnEcho()) {
    return;             // our own write, bouncing back
}

if ($envelope->isOwnEcho(seconds: 60)) { /* tighter window */ }
```

Anything it cannot establish reads as *not* an echo — a missing field errs toward
looking at an event rather than dropping it.

> **Changed in 0.19.0.** `caused_by_hub` / `$envelope->causedByHub` is gone. It
> promised causality and measured authorship, so a hand-edit on a Hub-booked
> document arrived flagged `true`. `hubAuthored` carries that same fact under an
> honest name, and `hubLastWroteAt` supplies the timing it was missing. If you
> filtered on `causedByHub`, replace it with `isOwnEcho()`.

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

## Closing the endpoint

`hub.webhook.enabled` (`EMEQ_HUB_WEBHOOK_ENABLED`, default `true`) gates the
route through `Emeq\HubSdk\Http\Middleware\HubWebhooksEnabled`:

```php
use Emeq\HubSdk\Http\Middleware\HubWebhooksEnabled;

Route::webhooks('webhooks/emeq-hub', 'emeq-hub')
    ->middleware(HubWebhooksEnabled::class);
```

It exists for the window in which the code is deployed but `webhook_calls` has
not reached every database that has to hold it — every tenant DB, in a multi-DB
app. With the endpoint open and the table missing, each delivery 500s and Hub
retries a 5xx five times over roughly three hours.

Gating in middleware rather than around `Route::webhooks()` keeps the route
table identical in both states: flipping the flag needs no `route:cache`
rebuild, and the closed state is reachable from a test.

The default is open, because that is what a route you just registered already
does. Set it to `false` before the deploy and to `true` once the migration has
landed everywhere.
