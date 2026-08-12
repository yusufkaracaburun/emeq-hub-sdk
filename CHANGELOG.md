# Changelog

## [Unreleased]

Second half of the architecture audit — the 🟡 rows.

### Changed

- **Breaking:** accounting collection getters (`documents()`, `bankStatements()`,
  `ledgerAccounts()`, `taxCodes()`, `customers()`, `suppliers()`) return an
  `AccountingPage` with `items` and `nextCursor` instead of a raw array. Hub
  answers these with `{data: [...], next_cursor: "…"}`; the previous union
  return type pushed that shape onto every caller.
- **Breaking:** `SpatieWebhookClientConfig::make()` takes `$signingSecret` as its
  required first argument and no longer falls back to `config()`.
- `IntegrationController` takes `Hub` and both resolver contracts as constructor
  parameters.
- Resources share an abstract `Resources\Resource` base.
- `HubWebhookHeaders::whereEventId()` owns the stored-header shape for queries.
- Untrusted JSON and consumer config are narrowed rather than cast: a non-scalar
  now reads as absent instead of becoming `"Array"`.
- Larastan raised from level 5 to 9.

### Removed

- The unreachable second `//` guard in `OAuthReturnUrl`, the empty
  `HubConnector::boot()` override, and the `HubManager` alias that named a class
  which does not exist.

### Development tooling

- `CONTEXT.md` and `docs/adr/` record the domain language, the layer rules and
  the two non-obvious decisions (provider-agnostic surface, publish-only
  migrations). Both are `export-ignore`d.

## [0.7.0] — 2026-08-12

Architecture audit follow-up: every 🔴/🟠 finding from
`docs/reviews/2026-08-12-whole-repo-architecture-audit.md`, plus one blocker the
audit itself missed.

### Fixed

- **Every accounting `GET` was unusable.** `GetAccountingRequest` promoted a
  `readonly array $query`, redeclaring Saloon's non-readonly `Request::$query`,
  which is a fatal error at class load. The class sat in `phpstan excludePaths`
  and had no test, so nothing caught it. Constructor parameter renamed to
  `$queryParameters`; the exclusion is gone and the suite is clean without it.
- **A crashed webhook job suppressed its own redelivery.** `alreadyProcessed()`
  treats a row with a null `exception` as handled, but only Spatie's synchronous
  path ever wrote that column. `ProcessHubWebhookJob::failed()` now records the
  exception, with explicit `$tries` / `$backoff`.
- **Concurrent redeliveries of one event id both processed.** The dedupe guard
  now runs under a cache lock keyed on config name + event id.
- **A restored multi-DB job silently dropped its webhook.** `__unserialize()`
  rebuilds a `WebhookCall` holding only an id; the default `resolveWebhookCall()`
  returned it as-is, so the payload was null and the job logged
  `invalid_payload_in_job` and returned. It now reloads the row — after
  `bindAccountContext()`, so on the right connection.

### Changed

- **Breaking:** `HubWebhookEvent` is a backed enum and `HubWebhookEnvelope::$event`
  is an enum case. Case names are unchanged, so
  `$envelope->event === HubWebhookEvent::CONNECTION_REVOKED` still works; reading
  the event as a string needs `->value`. Unknown events decode to `UNMAPPED`.
- **Breaking:** an unresolvable account id throws `MissingConfigurationException`
  (503, catchable as `HubException`) instead of `InvalidArgumentException`.
  `Integrations::list()` documents why the catalog is account-optional.
- **Breaking:** a malformed `hub.oauth.return_path` raises
  `MissingConfigurationException` (503) instead of `ValidationException` (422) —
  it is a deployment mistake, not caller input. The `error` code is unchanged.
- **Breaking:** default route middleware is now
  `['api', 'auth:sanctum', 'throttle:60,1']`. `EMEQ_HUB_ROUTES_MIDDLEWARE` is
  comma-split and cannot express `throttle:60,1`; use a named limiter when
  overriding.
- **Breaking:** `ProcessHubWebhookJob` owns `$accountId` / `$webhookCallId` and
  `accountIdForHandle()` is gone; `SerializesHubWebhookByIds` is reduced to
  `__serialize` / `__unserialize`. Subclasses that overrode
  `accountIdForHandle()` move that logic to the constructor or
  `resolveWebhookCall()`.
- New `hub.webhook.lock_store` (`EMEQ_HUB_WEBHOOK_LOCK_STORE`). The store must
  support atomic locks — Laravel's `database` default needs the framework's
  `cache_locks` table — otherwise the job raises
  `MissingConfigurationException` rather than racing.
- Error-envelope decoding lives in one place (`Http\HubErrorResponse`); the
  response middleware and the connector's exception hook both delegate to it.

### Development tooling

Nothing here ships: every path below is `export-ignore`d.

- Laravel Boost as a dev dependency, wired for a package repository. An
  `artisan` shim boots a bare app rooted at the package so Boost resolves
  `base_path()` here instead of inside `vendor/`. Guidelines, skills and MCP
  config are generated for the detected agents; app-only MCP tools are disabled
  in `config/boost.php`.
- Agent artefacts are deduplicated with symlinks: `.ai/skills/<name>` is the one
  copy of each skill, `CLAUDE.md` the one copy of the guidelines, `.mcp.json` the
  one MCP config.
- `docs/reviews/2026-08-12-whole-repo-architecture-audit.md` — the audit this
  release answers.

## [0.6.0] — 2026-08-12

### Changed

- **Breaking:** single publishable config — webhook wiring lives under
  `hub.webhook.{secret,name,profile,job}`. `HubServiceProvider` upserts the
  Hub entry into Spatie `webhook-client.configs` at boot.
- **Breaking:** removed publish tag `hub-webhook-client` and
  `config/webhook-client.php.stub`. `hub:install` publishes only `hub-config`
  + `hub-migrations`.
- **Breaking:** `hub.webhook_secret` → `hub.webhook.secret`.
- Multi-DB consumers override `hub.webhook.job` / `hub.webhook.profile` in
  `config/hub.php` instead of a separate Spatie config file.
- Branch alias `dev-master` → `0.6.x-dev`.
- README / AI prompt require `^0.6`.

## [0.5.1] — 2026-08-12

### Fixed

- `create_webhook_calls_table` migration stub now includes `down()` (`dropIfExists`) so consumers can roll back.

## [0.5.0] — 2026-08-12

### Added

- Publishable Spatie `webhook_calls` migration (`--tag=hub-migrations`).
- Publishable `webhook-client.php` stub (`--tag=hub-webhook-client`).
- `php artisan hub:install` (config + migrations + webhook-client + checklist).
- Domain events: `HubWebhookReceived`, `HubConnectionRevoked`, `HubWebhookIgnored`.
- Explicit `illuminate/{http,routing,database}` requirements.
- `.gitattributes` export-ignore for tests/tooling.
- Branch alias `dev-master` → `0.5.x-dev`.

### Changed

- Package short name `hub` → publish tags `hub-config` / `hub-migrations`
  (was `hub-sdk-*` on 0.4.0).
- `SpatieWebhookClientConfig` reads `config('hub.webhook_secret')` (config:cache-safe).
- Webhook dedupe uses JSON header query instead of loading all prior rows.
- `SerializesHubWebhookByIds` no longer serializes the transient queue `job`.
- Routes file renamed to `routes/hub.php`.
- README / AI prompt require `^0.5`.

## [0.4.0] — 2026-08-12

### Added

- Inbound Hub webhook helpers for Laravel consumers:
  - `HubWebhookEnvelope`, `HubWebhookHeaders`, `HubWebhookEvent`
  - `ResolvesWebhookAccount` contract
  - `HubWebhookProfile` + `ProcessHubWebhookJob` (Spatie webhook-client)
  - `SerializesHubWebhookByIds` for multi-DB queue workers
  - `SpatieWebhookClientConfig::make()` config builder
- Config: `hub.webhook_secret` (`EMEQ_HUB_WEBHOOK_SECRET`)
- Dependency: `spatie/laravel-webhook-client` ^3.4

## [0.3.0] — 2026-08-11

### Changed

- **BFF is Hub-portal-first.** Shipped routes are only:
  - `GET …/integrations` (optional status list)
  - `POST …/integrations/connect-session` (mint hosted `/connect` URL)
- Removed BFF `POST …/integrations/{provider}/connect` and
  `DELETE …/integrations/{connection}`. Connect / disconnect live on Hub’s
  hosted page — one UI for every consumer. Programmatic
  `Hub::oauth()->init()` / `Hub::connections()->delete()` remain on the facade.

### Migration

Replace in-app per-provider connect buttons with one CTA that
`POST`s `…/integrations/connect-session` and redirects to `url`.

## [0.2.1] — 2026-08-11

### Added

- `Hub::connectSessions()->create()` + BFF `POST …/integrations/connect-session`
  (Hub hosted `/connect/{account}` handoff)

## [0.2.0] — 2026-08-11

### Added

- Opt-in integration BFF routes (`EMEQ_HUB_ROUTES`): list / connect / destroy
- `ResolvesAccountDisplayName` contract for Hub account `display_name` on connect
- Config: `hub.routes.*`, `hub.oauth.return_path`

### Security / hardening

- `EMEQ_HUB_ROUTES` defaults to **false** (explicit opt-in)
- Refuse empty `hub.routes.middleware` (no unauthenticated BFF)
- Unbound `ResolvesAccountId` → JSON `503 missing_account_resolver`
- Validate `hub.oauth.return_path` (relative `/…` only; reject scheme / `//`)
- Always log Hub BFF errors; destroy only tenant-owned connections

## [0.1.0] — 2026-08-11

### Added

- Initial Laravel 13 + Saloon v4 consumer SDK for Hub `/v1`
- Provider-agnostic resources: accounts, integrations, OAuth init, connections, accounting
- Typed Hub error envelope mapping
- `ResolvesAccountId` contract for server-side tenant → Hub account binding
- README AI prompt (English) to install/configure the SDK in a Laravel consumer app
- README: API surface map, error table, pitfalls, links to Hub consumer docs (English)
