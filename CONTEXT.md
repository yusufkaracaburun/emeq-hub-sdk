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
Support/     framework-light helpers
Exceptions/  HubException and its subclasses
Events/      Laravel events consumers listen for
```

Rules:

- `Resources/` and `Webhooks/` may use `Support/`, `Exceptions/`, `Contracts/`
  and `Http/`. Nothing depends on `Http/Controllers/`.
- `Support/` stays framework-light. `OAuthReturnUrl` is the one exception; it
  needs the request host.
- Everything a consumer is meant to touch lives in `Facades\Hub`, `Contracts\*`,
  `Resources\*`, `Webhooks\*`, `Events\*`. `Http\*` is internal.
- Every failure a consumer can hit is a `HubException` — including configuration
  mistakes, so one catch clause suffices.

## Standing constraints

- **Provider-agnostic.** A new Hub partner must appear through
  `integrations()->list()` and `oauth()->init($key)` without an SDK release. No
  `if ($provider === 'exact')`, no per-partner classes, no allowlist.
- **Account context is server-side.** Derived from `ResolvesAccountId` or passed
  explicitly by the consumer's own code. Never read from the request.
- **Publish-only migrations.** The package ships a `webhook_calls` migration stub
  but never runs it; multi-DB consumers decide which connection it lands on.
- **The public API is a contract.** Changes to `Resources\*`, `Contracts\*`,
  `Webhooks\*` or `Events\*` are breaking. Keep `CHANGELOG.md` and `README.md` in
  step and follow semver.

## Decisions

See [`docs/adr/`](docs/adr/). Do not re-litigate a landed ADR without superseding
it.
