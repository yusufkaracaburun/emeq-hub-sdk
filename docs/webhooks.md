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

Override `onConnectionRevoked()` / `onIgnored()` on a job subclass if you prefer
hooks over the `Events\*` listeners.

`HubWebhookEvent` is a backed enum (keep in sync with Hub `CanonicalEvent`);
`$envelope->event` is an enum case, and an event added by Hub after your SDK
release decodes to `HubWebhookEvent::UNMAPPED` rather than throwing.

```php
if ($envelope->event === HubWebhookEvent::CONNECTION_REVOKED) { /* … */ }
$wireValue = $envelope->event->value; // 'connection.revoked'
```

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
