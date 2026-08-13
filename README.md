# emeq Hub SDK

Laravel 13 consumer client for the emeq Hub `/v1` API (Saloon v4).

**Provider-agnostic:** new Hub partners (Moneybird, …) appear via
`Hub::integrations()->list()` and `Hub::oauth()->init($provider, …)` without an
SDK release. Partner wire stays in the Hub + `emeq/*-api` packages — this SDK
does **not** expose per-partner pass-through.

Payload shapes, OAuth UX, and OpenAPI live in the Hub docs — see
[Further reading](#further-reading). This README covers install, call sites and
the API surface; webhook wiring lives in [`docs/webhooks.md`](docs/webhooks.md).

## Install

```bash
composer config repositories.emeq-hub-sdk vcs https://github.com/yusufkaracaburun/emeq-hub-sdk.git
composer require emeq/hub-sdk:^0.9
```

```bash
php artisan hub:install
# or publish selectively:
php artisan vendor:publish --tag=hub-config
php artisan vendor:publish --tag=hub-migrations
```

```env
EMEQ_HUB_BASE=https://hub.emeq.nl
EMEQ_HUB_PAT=your-sanctum-pat
EMEQ_HUB_TIMEOUT=30
EMEQ_HUB_WEBHOOK_SECRET=shared-with-hub-consumer-callback-secret
# Opt-in BFF routes (default off) — enable + tune middleware to your guard
EMEQ_HUB_ROUTES=true
EMEQ_HUB_ROUTES_PREFIX=api
# Default when unset: api,auth:sanctum,throttle:60,1 — this var is comma-split,
# so use a named limiter (throttle:hub) if you override it.
EMEQ_HUB_ROUTES_MIDDLEWARE=api,auth:sanctum
# Cache store for webhook dedupe locks. Empty = default store (must support locks).
EMEQ_HUB_WEBHOOK_LOCK_STORE=redis
# Relative path on YOUR host only (no https://…). Empty = omit return_url.
EMEQ_HUB_OAUTH_RETURN_PATH=/settings/integrations?oauth=1
```

### Checklist

1. Publish config + migrations (`hub:install` or tags above).
2. Set `EMEQ_HUB_*` in `.env`.
3. Bind `ResolvesAccountId` (`accountId()` + `displayName()`).
4. Receiving Hub webhooks? Bind `ResolvesWebhookAccount`, register the route and
   migrate `webhook_calls` — [`docs/webhooks.md`](docs/webhooks.md). The package
   does **not** auto-run migrations.

The package registers auth-protected routes when `EMEQ_HUB_ROUTES=true`
(default `false`). Middleware must be non-empty **and carry an `auth`-family
entry** — exactly `auth`, `auth:*` or `auth.basic` (case-sensitive;
`auth.session` does not count). Otherwise boot fails. If your auth middleware is
named something else (`tenant.auth`), set `hub.routes.allow_unauthenticated` to
`true` to declare that deliberate.

| Method | Path | Action |
|---|---|---|
| `GET` | `/{prefix}/integrations` | optional: list providers + status |
| `POST` | `/{prefix}/integrations/connect-session` | mint Hub hosted `/connect` URL |

**Recommended UX:** one button that `POST`s `connect-session` and redirects to
`url`. Users manage every provider (connect / disconnect / status) on Hub —
do not re-implement per-provider OAuth in your app. Use `Hub::oauth()` /
`Hub::connections()` only for server-side automation.

Set `EMEQ_HUB_OAUTH_RETURN_PATH` to a path on **your** host (or leave empty).
The SDK never assumes an app-specific URL.

## Account binding

Bind your tenant → Hub `external_id` server-side:

```php
use Emeq\HubSdk\Contracts\ResolvesAccountId;

class HubAccountIdResolver implements ResolvesAccountId
{
    public function accountId(): string
    {
        return (string) current_tenant_id(); // your app
    }

    public function displayName(): ?string
    {
        return current_tenant_name(); // null is fine — Hub names the account
    }
}

// AppServiceProvider
$this->app->bind(ResolvesAccountId::class, HubAccountIdResolver::class);
```

## Inbound Hub webhooks

Spatie `webhook-client` bases live in the SDK; apps only wire tenancy + handlers.
One published file: `config/hub.php` — at boot the package upserts the Hub entry
into Spatie’s `webhook-client.configs` from `hub.webhook.*`. Listen for these
rather than cargo-culting empty profile/job subclasses:

| Event | When |
|---|---|
| `HubWebhookReceived` | Every accepted envelope (before per-event hooks) |
| `HubConnectionRevoked` | `connection.revoked` |
| `HubWebhookIgnored` | Other canonical events (default: log only) |

Wiring, multi-DB connection placement (**gets this wrong and it fails
silently**), and deduplication: [`docs/webhooks.md`](docs/webhooks.md).

## Usage

```php
use Emeq\HubSdk\Facades\Hub;

$providers = Hub::integrations()->list(); // data-driven
$init = Hub::oauth()->init($providers[0]['key'], returnUrl: $url);

Hub::accounts()->create('tenant-1', 'Acme B.V.'); // treat 409 as "already exists"
Hub::connections()->delete($connectionId);

// Canonical accounting — Hub picks the partner adapter.
// Idempotency-Key is required on create (passed as $idempotencyKey).
Hub::accounting()->validateDocument($payload);
Hub::accounting()->createDocument($payload, idempotencyKey: (string) Str::uuid());
Hub::accounting()->capabilities();

// Collection reads are cursor-paginated and return an AccountingPage.
$page = Hub::accounting()->documents(['type' => 'sales_invoice']);

foreach ($page->items as $document) {
    // …
}

while ($page->hasMore()) {
    $page = Hub::accounting()->documents([
        'type' => 'sales_invoice',
        'cursor' => $page->nextCursor,
    ]);
}
```

## API surface

Prefer these from app code: `Facades\Hub`, `Contracts\*`, `Resources\*`,
`Webhooks\*`, `Events\*`. `Http\*` is package-internal (BFF / Saloon).

| SDK | Hub |
|---|---|
| `Hub::accounts()->create(...)` | `POST /v1/accounts` |
| `Hub::integrations()->list(...)` | `GET /v1/integrations` |
| `Hub::connectSessions()->create(...)` | `POST /v1/connect-sessions` |
| `Hub::oauth()->init($provider, ...)` | `POST /v1/oauth/{provider}/init` |
| `Hub::connections()->get($id)` | `GET /v1/connections/{id}` |
| `Hub::connections()->delete($id)` | `DELETE /v1/connections/{id}` |
| `Hub::accounting()->validateDocument(...)` | `POST /v1/accounting/documents/validate` |
| `Hub::accounting()->createDocument(..., $idempotencyKey)` | `POST /v1/accounting/documents` |
| `Hub::accounting()->documents(...)` | `GET /v1/accounting/documents` |
| `Hub::accounting()->bankStatements(...)` | `GET /v1/accounting/bank-statements` |
| `Hub::accounting()->ledgerAccounts(...)` | `GET /v1/accounting/ledger-accounts` |
| `Hub::accounting()->taxCodes(...)` | `GET /v1/accounting/tax-codes` |
| `Hub::accounting()->customers(...)` | `GET /v1/accounting/customers` |
| `Hub::accounting()->suppliers(...)` | `GET /v1/accounting/suppliers` |
| `Hub::accounting()->capabilities()` | `GET /v1/accounting/capabilities` |
| `Hub::accounting()->referenceData()` | `GET /v1/accounting/reference-data` |
| `Hub::accounting()->mapping()` / `updateMapping(...)` | `GET` / `PUT /v1/accounting/mapping` |
| `Hub::accounting()->sync(...)` | `POST /v1/accounting/sync` |

`$provider` is a free string (Hub discovery `key`) — no SDK allowlist.
Account context uses `X-Account-Id` / `account_external_id` from
`ResolvesAccountId` or an explicit argument.

## Errors

Failed Hub responses throw `Emeq\HubSdk\Exceptions\HubException` (or a subclass).
Envelope fields map to public properties: `error`, `category`, `requestId`, `status`.

| HTTP / category | Exception |
|---|---|
| 401 / `AUTHENTICATION_ERROR` | `AuthenticationException` |
| 403 / `AUTHORIZATION_ERROR` | `AuthorizationException` |
| 404 | `NotFoundException` |
| 422 / `VALIDATION_ERROR` | `ValidationException` |
| 429 | `RateLimitException` |
| ≥ 500 | `ServerException` |
| Missing `EMEQ_HUB_*`, unresolvable account id, bad `return_path`, non-lockable cache store | `MissingConfigurationException` (503) |

Every SDK failure is a `HubException` — configuration mistakes included, so
`catch (HubException $e)` around SDK calls is sufficient.

Log `requestId` when present; it matches Hub `X-Request-Id` / envelope `request_id`.

## Pitfalls

- **PAT in the browser** — always call Hub from your Laravel backend via this SDK.
- **Account id from the client** — never trust `X-Account-Id` / `account_external_id` from the request; derive via `ResolvesAccountId`.
- **`Hub::connections()` is PAT-scoped, not account-scoped** — Hub resolves `/v1/connections/{id}` against the Consumer behind your token and ignores account context, so `get()` / `delete()` reach every connection of every account you own. Verify ownership yourself; never forward a connection id straight from a request.
- **Connection ids are the `con_…` public id** — the value `integrations()->list()`, `oauth()->init()` and the `connection_revoked` webhook hand back. Hub's numeric key is internal; do not store it. (Hub only started accepting the public id here in August 2026 — older Hub deployments return a 500.)
- **Hardcoded providers** — render what `integrations()->list()` returns; no `if ($provider === 'exact')`.
- **Partner SDKs in the consumer** — do not require `emeq/exact-api` here; those are Hub-internal.
- **`return_url`** — snake_case on the wire; build the URL server-side from your host (open-redirect guard on the Hub).
- **Idempotency** — `createDocument` requires a stable `idempotencyKey` per logical write.

## AI prompt — wire this SDK into your Laravel app

A ready-to-paste prompt for your coding agent (Cursor, Claude Code, …) that
installs and configures the SDK against your tenant model:
[`docs/agent-prompt.md`](docs/agent-prompt.md).

## Growth model

| Change | Where |
|---|---|
| New partner | Hub only — SDK unchanged |
| New canonical `/v1` endpoint | This SDK (+ tag) |
| Partner HTTP/auth/DTOs | `emeq/<partner>-api` (Hub-internal) |

## Further reading

- [Consumer onboarding](https://github.com/yusufkaracaburun/emeq-hub/blob/master/docs/consumer-onboarding.md) — Hub admin + consumer invariants (B1–B4)
- [Consumer integration guide](https://github.com/yusufkaracaburun/emeq-hub/blob/master/docs/consumer-integration-guide.md) — flows, payloads, accounting, webhooks, agent prompts
- Hub OpenAPI UI: `{EMEQ_HUB_BASE}/docs/api`

Contributing to this package: [`CONTEXT.md`](CONTEXT.md) for the domain language
and structural rules, [`docs/adr/`](docs/adr/) for the decisions behind them.

## Requirements

- PHP 8.3+
- Laravel 13

## Local development

```bash
composer install
composer test        # Pest on Orchestra Testbench
composer analyse     # Larastan
composer format      # Pint
```

Laravel Boost wiring, the `artisan` shim and the symlinked agent artefacts:
[`docs/local-development.md`](docs/local-development.md).
