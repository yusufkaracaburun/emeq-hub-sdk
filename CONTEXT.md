# CONTEXT

Domain language and structural rules for `emeq/hub-sdk`. Read this before
changing anything under `src/`.

## What this is

A Laravel consumer SDK for the emeq Hub `/v1` API. It is installed *into* other
Laravel applications; it is not an application. There is no `app/`, no database
of its own, and no HTTP kernel — only a small opt-in BFF surface.

## Glossary

| Term | Means |
| --- | --- |
| **Hub** | The emeq Hub service. Owns every partner integration and the canonical `/v1` API. |
| **Consumer** | The Laravel application that installs this package. |
| **Provider** / partner | An accounting or billing system behind Hub (Exact, Moneybird, …). Identified by a free-form `key` from Hub's discovery endpoint — never an SDK constant. |
| **Account** | A consumer tenant as Hub knows it. Referred to by `external_id` and resolved server-side through `ResolvesAccountId`; never taken from a request. |
| **Connection** | One authorised link between an account and a provider. |
| **Connect session** | A short-lived URL to Hub's hosted `/connect/{account}` page. The recommended way to connect or disconnect. |
| **Envelope** | The body shape of a Hub → consumer webhook (`HubWebhookEnvelope`). |
| **Canonical event** | A `HubWebhookEvent` case. Mirrors Hub's `CanonicalEvent`; anything unknown decodes to `UNMAPPED`. |
| **BFF** | The two opt-in routes this package registers (`integrations`, `connect-session`). Off by default. |

## Layout and dependency direction

```
Contracts/   consumer-implemented seams (ResolvesAccountId, ResolvesWebhookAccount, …)
Resources/   the public call surface — one class per Hub resource, extending Resource
Http/        Saloon connector, request classes, BFF controller. Package-internal.
Webhooks/    inbound Hub webhooks: envelope, headers, profile, job
Booking/     the ledger and the retry policy: booker, runner, outcomes, HubDocument
Backlog/     "what of mine is not booked yet" — the join over consumer sources
Support/     framework-light helpers
Testing/     HubMock + the captured fixtures it serves, for consumer suites
Exceptions/  HubException and its subclasses
Events/      Laravel events consumers listen for
```

Rules:

- `Resources/` and `Webhooks/` may use `Support/`, `Exceptions/`, `Contracts/`
  and `Http/`. Nothing depends on `Http/Controllers/`.
- `Webhooks/` does not reach into `Booking/`. A delivery that has to touch the
  ledger does it through a listener the service provider registers
  (`AccountingChangeRecorder` on `HubWebhookReceived`), which also keeps the
  behaviour when a consumer subclasses `ProcessHubWebhookJob` and forgets
  `parent::`. `Backlog/` reads `Booking/`; not the other way round.
- `Support/` stays framework-light — no `Illuminate\Http` imports. Helpers that
  need request data take it as a scalar (`OAuthReturnUrl::fromConfigPath()`
  takes an origin string, not a `Request`).
- Everything a consumer is meant to touch lives in `Facades\Hub`, `Contracts\*`,
  `Resources\*`, `Webhooks\*`, `Events\*`, `Testing\*`. `Http\*` is internal.
- `Testing/fixtures/*.json` are captured Hub responses, never invented ones. A
  new fixture comes from `tools/capture-fixtures.php` and gets redacted before
  it lands; an endpoint that has not been captured gets no factory.
- Every failure a consumer can hit is a `HubException` — including configuration
  mistakes, so one catch clause suffices.

## Standing constraints

- **Provider-agnostic.** A new Hub partner must appear through
  `integrations()->list()` and `oauth()->init($key)` without an SDK release. No
  `if ($provider === 'exact')`, no per-partner classes, no allowlist.
- **Account context is server-side.** Derived from `ResolvesAccountId` or passed
  explicitly by the consumer's own code. Never read from the request.

- **`Resources\Connections` is the one PAT-scoped surface.** Hub resolves
  `/v1/connections/{id}` against the Consumer behind the token and reads no
  account context (`ConnectionController::findOwnedConnection()` filters on
  `consumer_id` alone), so the SDK sends none. Every other account-bearing call
  carries one. Multi-tenant consumers verify ownership before calling it; the
  SDK cannot do it for them.

- **The BFF refuses to boot without auth.** `hub.routes.middleware` must carry an
  `auth`-family entry unless `hub.routes.allow_unauthenticated` says otherwise.
  The endpoint mints partner-OAuth handoff URLs, so unauthenticated is a
  decision, never a default.
- **Publish-only migrations.** The package ships a `webhook_calls` migration stub
  but never runs it; multi-DB consumers decide which connection it lands on.
- **The public API is a contract.** Changes to `Resources\*`, `Contracts\*`,
  `Webhooks\*` or `Events\*` are breaking. Keep `CHANGELOG.md` and `README.md` in
  step and follow semver.

## Decisions

See [`docs/adr/`](docs/adr/). Do not re-litigate a landed ADR without superseding
it.
