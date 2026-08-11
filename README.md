# emeq Hub SDK

Laravel 13 consumer client for the emeq Hub `/v1` API (Saloon v4).

**Provider-agnostic:** new Hub partners (Moneybird, …) appear via
`Hub::integrations()->list()` and `Hub::oauth()->init($provider, …)` without an
SDK release. Partner wire stays in the Hub + `emeq/*-api` packages — this SDK
does **not** expose per-partner pass-through.

Payload shapes, OAuth UX, webhooks, and OpenAPI live in the Hub docs — see
[Further reading](#further-reading). This README covers package install and call
sites.

## Install

```bash
composer config repositories.emeq-hub-sdk vcs https://github.com/yusufkaracaburun/emeq-hub-sdk.git
composer require emeq/hub-sdk:^0.2
```

```env
EMEQ_HUB_BASE=https://hub.emeq.nl
EMEQ_HUB_PAT=your-sanctum-pat
EMEQ_HUB_TIMEOUT=30
# Opt-in BFF routes (default off) — enable + tune middleware to your guard
EMEQ_HUB_ROUTES=true
EMEQ_HUB_ROUTES_PREFIX=api
EMEQ_HUB_ROUTES_MIDDLEWARE=api,auth:sanctum
# Relative path on YOUR host only (no https://…). Empty = omit return_url.
EMEQ_HUB_OAUTH_RETURN_PATH=/settings/integrations?oauth=1
```

Publish config (optional):

```bash
php artisan vendor:publish --tag=hub-config
```

The package registers three auth-protected routes when `EMEQ_HUB_ROUTES=true`
(default `false`). Middleware must be non-empty — empty middleware refuses to boot.

| Method | Path | Action |
|---|---|---|
| `GET` | `/{prefix}/integrations` | list providers + status |
| `POST` | `/{prefix}/integrations/connect-session` | Hub hosted connect page URL |
| `POST` | `/{prefix}/integrations/{provider}/connect` | ensure account + OAuth init |
| `DELETE` | `/{prefix}/integrations/{connection}` | revoke (tenant-owned only) |

Set `EMEQ_HUB_OAUTH_RETURN_PATH` to a path on **your** host (or leave empty to let Hub use the request Origin). The SDK never assumes an app-specific URL.

## Account binding

Bind your tenant → Hub `external_id` server-side:

```php
use Emeq\HubSdk\Contracts\ResolvesAccountDisplayName;
use Emeq\HubSdk\Contracts\ResolvesAccountId;

class HubAccountIdResolver implements ResolvesAccountId, ResolvesAccountDisplayName
{
    public function accountId(): string
    {
        return (string) current_tenant_id(); // your app
    }

    public function displayName(): ?string
    {
        return current_tenant_name(); // optional; null is fine
    }
}

// AppServiceProvider
$this->app->bind(ResolvesAccountId::class, HubAccountIdResolver::class);
$this->app->bind(ResolvesAccountDisplayName::class, HubAccountIdResolver::class);
```

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
```

## API surface

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
| Missing `EMEQ_HUB_*` | `MissingConfigurationException` |

Log `requestId` when present; it matches Hub `X-Request-Id` / envelope `request_id`.

## Pitfalls

- **PAT in the browser** — always call Hub from your Laravel backend via this SDK.
- **Account id from the client** — never trust `X-Account-Id` / `account_external_id` from the request; derive via `ResolvesAccountId`.
- **Hardcoded providers** — render what `integrations()->list()` returns; no `if ($provider === 'exact')`.
- **Partner SDKs in the consumer** — do not require `emeq/exact-api` here; those are Hub-internal.
- **`return_url`** — snake_case on the wire; build the URL server-side from your host (open-redirect guard on the Hub).
- **Idempotency** — `createDocument` requires a stable `idempotencyKey` per logical write.

## AI prompt — wire this SDK into your Laravel app

Paste the prompt below into your coding agent (Cursor, Claude Code, …) to
install and configure `emeq/hub-sdk` against your tenant model. Fill in the
`{…}` placeholders before pasting.

The prompt covers **install + config + account binding**. Integration BFF routes
ship with the package (`EMEQ_HUB_ROUTES`). UI cards / privacy checkbox belong in
your app; build those next with the Hub
[consumer-integration-guide](https://github.com/yusufkaracaburun/emeq-hub/blob/master/docs/consumer-integration-guide.md)
agent prompts.

```text
Install and configure emeq/hub-sdk in my Laravel app so we can call the emeq Hub
(/v1) server-side. Read the package README first:
https://github.com/yusufkaracaburun/emeq-hub-sdk

CONTEXT
- App: {Laravel 13 / path to repo}
- Hub base URL: {https://hub.emeq.nl}
- PAT from env (empty or known): EMEQ_HUB_PAT
- Auth middleware for Hub routes: {api,auth:sanctum / api,auth:api / …}
- OAuth return path on my host: {/settings/integrations?oauth=1 — or empty}
- Tenants are distinguished by: {subdomain / instance.id / company_id / …
  — describe how you resolve the current tenant}
- Hub account `external_id` = {stable internal tenant id, e.g. instance.id —
  not email or domain}

DO THIS (in order)
1. Add Composer VCS repo and require:
   composer config repositories.emeq-hub-sdk vcs https://github.com/yusufkaracaburun/emeq-hub-sdk.git
   composer require emeq/hub-sdk:^0.2
2. Set in `.env` / `.env.example`:
   EMEQ_HUB_BASE={https://hub.emeq.nl}
   EMEQ_HUB_PAT=
   EMEQ_HUB_TIMEOUT=30
   EMEQ_HUB_ROUTES=true
   EMEQ_HUB_ROUTES_PREFIX=api
   EMEQ_HUB_ROUTES_MIDDLEWARE={api,auth:sanctum}
   EMEQ_HUB_OAUTH_RETURN_PATH={/settings/integrations?oauth=1}
3. Implement `Emeq\HubSdk\Contracts\ResolvesAccountId` in my app
   (e.g. `App\Integrations\Hub\HubAccountIdResolver`) mapping the current
   tenant to Hub `external_id` per CONTEXT above — server-side only.
   Optionally also implement `ResolvesAccountDisplayName` for Hub account names.
4. Bind that class in `AppServiceProvider::register()` to
   `ResolvesAccountId::class` (and `ResolvesAccountDisplayName::class` if used).
5. Explicitly set `EMEQ_HUB_ROUTES=true` (package default is false). Middleware
   must be non-empty. Do NOT hand-roll Hub HTTP clients or duplicate the
   package BFF routes. Use `Hub` facade only for extra Hub calls beyond the
   shipped routes.
6. Do not store tokens, connection state, or provider credentials in my DB.
   Status comes live from Hub::integrations()->list() / GET …/integrations.
7. Data-driven: hardcoded provider lists or `if ($provider === 'exact')` are
   forbidden. New Hub partners must work without code changes.
8. Errors: HubException subclasses map to JSON on the BFF routes; when calling
   Hub yourself, rethrow or map and log `requestId` when set.

DO NOT
- Browser / direct calls to the Hub (PAT stays server-side)
- Install emeq/exact-api or other partner SDKs in this consumer app
- Build per-partner pass-through wrappers
- Take X-Account-Id / account_external_id from the client request
- Re-implement GET/POST/DELETE …/integrations in my app (SDK owns those)

DONE WHEN
- composer show emeq/hub-sdk works
- ResolvesAccountId is bound
- Package routes respond under my auth middleware
- At least one feature/smoke test: list integrations (MockClient or Http fake)
  proves account id is derived server-side and ignores a spoofed request header
```

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

## Requirements

- PHP 8.3+
- Laravel 13
