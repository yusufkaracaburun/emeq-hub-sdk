# Changelog

## [0.14.0] — 2026-08-16

Found while wiring 0.13.0 into its first consumer.

### Changed

- **`Booking\CheckOutcome` carries a `status`**, the same vocabulary
  {@see BookingOutcome} already used: 200 when Hub answered, 404 / 403 / 422 for
  a document that is missing, not this user's or not mappable, 502 when Hub
  failed to answer and 503 when the check broke for an unrelated reason. It
  previously flattened all of those into a message plus a `retryable` flag, so a
  caller could not tell "your document is wrong" (the user's to fix) from "Hub
  could not answer" (nobody's) — and every consumer would have had to
  reconstruct that split from the message text.

  **Breaking:** the `retryable` property is gone; use `mayRetry()`, which reads
  the status. The constructor takes `status` as its third argument.

- **`Booking\Resources\CheckResultResource` exposes `status` and `may_retry`**,
  so a batch report can group what can be repeated without inspecting copy.

## [0.13.0] — 2026-08-16

The booking core moves out of the first consumer and into the SDK. Everything
here was running in production in `emeq/system`; what stayed behind is the part
that knows an app's data model (mappers, backlog sources, attachment renderers).
Nothing existing changed — this release is additive.

### Added

- **`Booking\HubDocument` + a `create_hub_documents_table` migration stub** —
  the ledger of what this consumer sent and what Hub answered, keyed the way Hub
  defines a document's identity: `(account_id, type, external_id)`. Why it lives
  in the consumer rather than being read back from Hub:
  [ADR-0003](docs/adr/0003-the-booking-ledger-lives-in-the-consumer.md).
  Publish-only, per ADR-0002. Reads `hub.booking.connection` and declares no
  connection of its own — a ledger on the wrong database answers "not booked
  yet" and the next run posts a duplicate into a real administration.

- **`Booking\DocumentBooker`** — books one canonical document and records the
  outcome. Takes a document array, not a model: mapping stays in the consumer.
  What it encodes, and what a second consumer would otherwise have to rediscover
  by posting duplicates into someone's bookkeeping:
  - `429`, `5xx` and `idempotency_request_in_progress` say nothing about the
    document, so they write **no row** and throw
    `BookingTemporarilyUnavailable`. "No row" and "a row saying failed" are the
    difference between a safe retry and a wrong one.
  - `document_already_posted`, `idempotency_key_reuse` and `upstream_rejected`
    are answers about this document — `rejected`, not `failed`, and not reported
    as an error.
  - A dropped connection records `unknown`: Hub may have posted it anyway, so
    nothing may resend it automatically.
  - A document already `posted` is never sent again. Identical content is a
    no-op and changed content is refused, which would demote a posted row.
  - `party_external_id` is pinned to whatever the first attempt used, so
    unlinking a party from its parent record cannot open a second relation
    upstream.
  - Concurrent attempts on one document fail fast instead of queueing, behind a
    lock scoped to `(account, external_id)` — not to type, because one invoice
    is a `sales_invoice` or a `credit_note` depending on its live total.
  - Attachments are rendered inside that error handling (pass a closure), so a
    renderer that throws records `attachment_render_failed` instead of losing
    the attempt.

- **`Booking\BookingOutcome`** — maps a decided ledger row to what a caller
  should say and with which status: booked (200), refused (422), needs a human
  (422 + `needsManualCheck`), retry later (503), Hub failed (502). Only 503
  answers `mayRetry()`.

- **`Booking\BookingLedger`** — account-scoped ledger reads, so no caller has to
  remember that an unscoped read shows another administration's bookings.

- **`Exceptions\DocumentNotBookable`** — base class for a consumer's own
  "this can never be sent" exceptions (a draft, a missing party), so the SDK can
  tell them apart from "did not book this time" without knowing their names.

- **`Support\DutchVatNumber`** — normalises and validates a Dutch VAT number
  under both check-digit schemes the Belastingdienst has issued (eleven-test and
  mod-97), adding the country code Dutch bookkeeping commonly leaves off.
  Anything that fails is dropped rather than sent: a wrong VAT number lands on
  the relation and ends up on returns.

- **`Booking\BookingRunner`** — checks and books by `(module, id)`, one or a
  batch, behind `Booking\Contracts\ResolvesBookableDocument`. Owns the table
  nobody wants to rediscover: missing is 404, unauthorised is 403, unmappable is
  a refusal, an undecided send is 503, and anything unexpected is reported
  rather than shown raw. A batch stops once `hub.booking.batch_seconds` is
  spent, so a run cannot outlive its request — safe to repeat with the
  remainder, because an already-posted document is never sent twice.

- **`Exceptions\DocumentNotAuthorized`** — the SDK's own word for "this user may
  not book it", so authorising stays entirely the consumer's (a gate, a policy,
  a role check) and this package gains no auth dependency.

- **`Backlog\BacklogRepository`** — "which of my documents are not booked yet",
  behind `Backlog\Contracts\ProvidesBacklogSources`. The consumer returns one
  query per module with nine named columns; this owns the left join against the
  ledger, the `not_booked` filter (the absence of a row, so it cannot ride the
  same `whereIn` as the others), sorting with stable tiebreakers, paging, and a
  summary counted over the whole filter rather than the page. Rows come back
  carrying their full ledger row, not just the joined status — a refusal's
  reason is the thing a backlog exists to show.

- **`Backlog\PostedDocuments`** — the "already in the bookkeeping" exclusion for
  consumer source queries. Ships here because getting its account scope wrong is
  silent: an unscoped exclusion hides another administration's postings from
  this one's backlog.

- **`Backlog\BacklogStatus` / `BacklogSummary`**, and JSON resources for both
  layers: `Booking\Resources\{BookingResource, BatchResultResource,
  CheckResultResource}` and `Backlog\Resources\{BacklogDocumentResource,
  BacklogSummaryResource}`. Routes, request validation and the response envelope
  stay the consumer's — those differ per app and always will.

- **Outcome copy in `en` and `nl`**, published with
  `vendor:publish --tag=hub-translations`, resolved in one place
  (`Booking\BookingMessages`) so republishing rewords every outcome at once.

- **`hub.booking.*` config** — `connection`, `lock_store`, `lock_seconds`,
  `batch_seconds`, `page_length`. The lock store must support atomic locks; one
  that cannot says so with a configuration error rather than letting two
  attempts post the same document.

### Not moved, deliberately

Mappers, backlog source queries, attachment renderers, controllers, form
requests and response envelopes stay in the consumer: they know a data model or
an app's auth, and generalising them would mean guessing at both. The Dutch
reverse-charge default (rate `0` on a shifted line meaning 21%) is a tenant-level
assumption, not a canonical one, and stays with the mapper that makes it.

## [0.12.1] — 2026-08-15

### Fixed

- **`IntegrationController::index()`'s docblock leaked into consumer API docs.**
  Scramble takes the first paragraph as an endpoint `summary`, so a consumer's
  generated OpenAPI carried this package's internal rationale about ADR-0001 as
  the public description of `GET /integrations`. Split into a one-line summary
  plus the rationale below it.

## [0.12.0] — 2026-08-15

Hub adds an additive `blocking` field to every `validateDocument()` finding.
This release adds the honest way to read it — including from a Hub that predates
the field — and closes an untyped leak this package's own consumer BFF had.

### Added

- **`Emeq\HubSdk\Resources\Finding::isBlocking()`** — reads a finding's
  `blocking` field without guessing at Hub's semantics. Some `warning`-severity
  findings reject the booking anyway (Hub says so in their own `message`);
  `blocking` is the field meant to replace parsing that text, and some Hub
  deployments will not send it yet. Absent or non-boolean answers `null` —
  never a guessed `false` — so a consumer cannot mistake "Hub hasn't told me"
  for "nothing blocks". `severity` and `valid` keep their existing meaning;
  nothing about them changed.

### Fixed

- **`IntegrationController::connectSession()` leaked `mixed` `url` /
  `expires_at`.** `$session['url'] ?? null` still typed as `mixed`, widening
  the consumer's generated schema for both fields to `any`. Narrowed with
  `is_string()`, same pattern `returnPath()` already used for config, behind
  an explicit `array{url: string|null, expires_at: string|null}` return type.

### Documentation

- **`IntegrationController::index()`** now states the shape it answers —
  `list<array<string, mixed>>`, the same type `Integrations::list()` already
  declares. Item keys stay untyped on purpose: Hub's discovery payload is
  data-driven per provider, and narrowing keys here would mean hard-coding a
  schema ADR-0001 deliberately keeps out of the SDK.

### Not done, on purpose

- **`validate-clean.json` and `validate-findings.json` were not recaptured.**
  Both predate `blocking`. Hand-editing the key into a "captured" fixture would
  turn honest test data into invented Hub semantics with a stamp of authority,
  so they stay as they are until `tools/capture-fixtures.php` reruns against a
  Hub that sends the field. Their current shape is also what an older Hub still
  answers, which is exactly the `null` path `Finding::isBlocking()` covers.

## [0.11.0] — 2026-08-13

Reverses the "not done, on purpose" call on test affordances from 0.10.1. That
call was right about the blocker — the SDK had no fixtures, only invented
payloads inline in its own tests — and wrong to leave it there. The blocker was
removable: capture the responses.

### Fixed

- **`Hub::accounting()->sync()` was dead on arrival.** `SyncAccountingRequest`
  promoted a readonly `$body`, which collides with the non-readonly `$body` that
  `HasJsonBody` declares — PHP fatals at class-load, so every call to `sync()`
  crashed the request. Exactly the trap `GetAccountingRequest` documents for
  `$query`, one class over, uncaught because nothing exercised `sync()`. The
  property is `$payload` now, and the mock factory below covers the call.

### Added

- **`Emeq\HubSdk\Testing\HubMock`** — canonical `MockResponse` factories for the
  accounting reads, `createDocument()`, `sync()`, the validation endpoint and
  the error envelopes, plus `HubMock::accounting()` for wiring the lot into
  `MockClient::global()` in one line, and `HubMock::fixture()` for the raw
  payload. New public namespace, hence the minor bump.
- **`src/Testing/fixtures/*.json`** — 16 responses captured from a live Hub
  against a connected provider, then redacted. Same files feed the SDK's own
  tests, so a consumer testing against `HubMock` tests against the shape this
  package treats as the truth.
- **`tools/capture-fixtures.php`** — the capture run itself, so the fixtures can
  be refreshed rather than hand-edited when they go stale. Reads and validation
  are side-effect free and run by default; booking and re-sync sit behind
  `--allow-write`. Development only, `export-ignore`d.

### Changed

- **The SDK's own accounting tests now read those fixtures** instead of the
  payloads they used to invent — the ones that asserted paging behaviour were
  asserting it against a shape Hub does not send.
- **README documents what the captures actually show.** `validateDocument()`
  answers `200` whether or not the document is valid, and a clean document still
  carries findings — a matched relation comes back as `info`, so `findings === []`
  is not the success test. `referenceData()` is grouped by kind with no `kind` on
  the items, `mapping()` is wrapped in `{mapping: …}` and holds composite
  `reverse_charge:21` keys, and a document read is a thin projection where
  `issue_date`, `party.name` and `lines` can all be empty.
- **New pitfall: `documents()` requires a `type`.** Hub answers a collection read
  without one with `400 invalid_query` / `VALIDATION_ERROR`, which the SDK raises
  as `ValidationException`.

- **The write path is captured too.** `createDocument()` answers `201` with the
  provider's own `external_ref` and `external_number` — neither is in the
  OpenAPI response schema, which says `{"type": "string"}` for this endpoint.
  Replaying the same `Idempotency-Key` returned a byte-identical body against a
  live Hub, so the idempotency contract the README states is now measured rather
  than asserted.
- **`POST` and `GET` on `/accounting/documents` share a URL**, and Saloon matches
  mocks on the URL alone, so one map cannot answer both. `accounting()` answers
  the read and a booking test keys the pattern itself. A test pins that
  behaviour, so the documentation of the trap cannot quietly stop being true.

### Not done, on purpose

- **Still no typed value objects for the canonical document.** 0.10.1 declined a
  hand-written mirror of the TypeScript definition in `emeq-app`; capturing real
  responses turned that from a judgement call into a measurement. That mirror is
  already drifted from the Hub's committed spec — it is missing
  `lines[].tax_treatment` and disagrees on the `issue_date` format — and the
  captured `reference-data` and `mapping` payloads contradict it further: items
  carry no `kind`, `vat_codes` holds composite keys, and `auto_create_relations`
  is not in the response at all. A PHP copy of an already-drifted copy would be
  the third answer to the same question. If types come, they get generated from
  `api.json`, where drift is a failing CI diff instead of a human error.

## [0.10.1] — 2026-08-13

Docs only — no API change. Both findings come from the first consumer building
against `Hub::accounting()`.

### Documentation

- **The idempotency example cancelled idempotency out.** The README showed
  `createDocument($payload, idempotencyKey: (string) Str::uuid())`. A fresh uuid
  per call means the retry after a timeout arrives with a new key, and Hub books
  the same document twice — the exact case the header exists for. The example now
  derives the key from `$payload['external_id']`, which the canonical contract
  names as the stable document key; the rule is stated in the pitfall list and in
  the `createDocument()` docblock, which said nothing about it.
- **How consumers mock the SDK.** 0.9.0 dropped `saloonphp/laravel-plugin`, so
  `Saloon::fake()` is gone and nothing documented what replaced it. New *Testing
  your integration* section: `MockClient::global([...])` keyed on URL patterns —
  `HubConnector` is a singleton `Saloon\Http\Connector`, so a global mock
  intercepts every call, and URL keys keep consumers out of the package-internal
  `Http\Request\*` namespace. Also warns that `global()` is `??=`, so a client
  left standing silently ignores the next test's mock data, and that the SDK
  ships no fixtures — shape mocks off the committed OpenAPI spec.

  Covered by a test that fails when the mocked URL stops matching, so the
  documented path cannot rot silently.

### Not done, on purpose

- **No typed value objects for the canonical document.** `createDocument()` /
  `validateDocument()` still take `array<string, mixed>`. A hand-written PHP
  mirror of the TypeScript definition in `emeq-app` would make a third copy of a
  contract that now has a committed owner (`api.json` in emeq-hub, CI-gated on
  drift), and that spec's request bodies are the reliable half — so generating
  DTOs later is cheap and mirroring by hand now is throwaway work.
- **No `Emeq\HubSdk\Testing` mock factories.** The proposal was to feed them from
  the fixtures the SDK's own tests use; those do not exist — the tests inline
  invented payloads. Canonical factories over invented shapes would only give
  consumers SDK fiction with a stamp of authority, and the spec cannot fix it
  (response schemas stay thin for provider pass-through). Blocked on capturing
  real Hub responses first.

## [0.10.0] — 2026-08-13

### Documentation

- **The Hub OpenAPI spec is now a committed artefact.** `/docs/api` was already
  live, but the exported `api.json` was gitignored and stale. Hub now commits it
  and fails CI on drift, so contract changes are visible as a diff. Noted in the
  further-reading block, with the caveat that request bodies are reliable and
  response schemas are still thin for provider pass-through endpoints.

- **README required `^0.7`.** On a 0.x that caret pins the minor, so consumers
  following the install block got 0.7.x — without the 0.9.0 route-auth guard.
  Both occurrences now require `^0.10`, and the `dev-master` branch alias moved
  to `0.10.x-dev`.
- **The `['name', 'id']` index claim is now conditional.** 0.9.0 documented it as
  one "the dedupe query needs". Measured on MySQL 5.7 / InnoDB with the real
  query: on a Hub-only `webhook_calls` the optimizer never picks it (`key=PRIMARY`
  at 50k and 200k rows, timings equal to having no index). It does pay off once
  the table carries several webhook configs — 21.5 ms → 12.4 ms at 50k rows with
  Hub at 20%. Documented as such.
- **README split.** 398 → 245 lines. Webhook wiring, connection placement and
  dedupe moved to `docs/webhooks.md`; the agent prompt to
  `docs/agent-prompt.md`; Boost / symlink tooling to
  `docs/local-development.md`. `docs/` is `export-ignore`d, so these are
  GitHub-only — the same choice `CONTEXT.md` already makes.

### Fixed

- **`webhookConfigName()` no longer hardcodes `emeq-hub`.** It reads
  `hub.webhook.name` — the value `HubServiceProvider` registers the Spatie config
  under. A consumer who renamed it had `alreadyProcessed()` filtering
  `webhook_calls.name` on a name no row carries, so dedupe matched nothing.
- **The auth-family check no longer accepts names Laravel cannot resolve to
  auth.** Aliases resolve by exact array key, so `AUTH:SANCTUM` was passed
  through as a class name; `auth.session` aliases `AuthenticateSession`, which
  invalidates sessions on password change and authenticates nobody. Both used to
  satisfy the guard. Accepted entries are now `auth`, `auth:*` and `auth.basic`.

### Changed

- **`HubWebhookDeduplicator::OPAQUE_EVENT_IDS` is a constructor argument.**
  Widening the sentinel list took a subclass to redefine a `protected const`;
  it is now the third constructor parameter, defaulting to `['no-id']`.
- **`HubRouteMiddleware::assertAuthenticated()` drops its
  `$allowUnauthenticated` flag.** The escape hatch is read once by the new
  `HubRouteMiddleware::validated()`, which is now the only way to obtain the
  configured stack — `routes/hub.php` no longer re-derives what
  `packageBooted()` validated.

## [0.9.1] — 2026-08-13

### Documentation

- **Which connection id to pass.** `Hub::connections()->get()` / `->delete()`
  take the `con_…` public id — the value `integrations()->list()`,
  `oauth()->init()` and the `connection_revoked` webhook all hand back. Hub's
  numeric primary key is accepted but internal.

  Hub itself only started accepting the public id on those two endpoints in
  August 2026; before that the documented connect flow returned a 500
  (`TypeError`, the controller type-hinted `int`). Nothing in the SDK changed —
  it already passed `string|int` straight through.

### Changed

- `dev-master` branch alias points at `0.9.x-dev`.

## [0.9.0] — 2026-08-12

Follow-up to the 2026-08-12 Laravel-extension audit. Five 🟠 and two 🟡, three of
them semver-breaking.

### Security

- **The BFF now refuses to boot without auth middleware.**
  `HubRouteMiddleware::assertNotEmpty()` claimed in its message that it was
  "refusing to register unauthenticated Hub BFF routes", but it only checked
  that the list was non-empty — `['api']` passed and registered an
  unauthenticated `POST …/integrations/connect-session`, which mints a Hub
  partner-OAuth handoff URL for whatever `ResolvesAccountId` returns. A new
  `assertAuthenticated()` requires an `auth`-family entry (`auth`, `auth:*`,
  `auth.*`).

  **Upgrading:** if your auth middleware is named outside that family
  (`tenant.auth`, a Sanctum wrapper), set `hub.routes.allow_unauthenticated` to
  `true` — or add `EMEQ_HUB_ROUTES_ALLOW_UNAUTHENTICATED=true`. Boot throws
  otherwise.

- **`Hub::connections()` is documented as PAT-scoped.** Hub resolves
  `/v1/connections/{id}` against the Consumer behind the token and reads no
  account context, so `get()` / `delete()` reach every connection of every
  account under that token. The SDK cannot narrow this — sending `X-Account-Id`
  would be ignored server-side and buy false confidence — so it is now stated in
  `Connections`, `CONTEXT.md` and the README pitfalls instead. Multi-tenant
  consumers must verify ownership before calling either method.

### Changed

- **Webhook dedupe moved to `HubWebhookDeduplicator`.** `ProcessHubWebhookJob`
  had grown to 370 LOC while also being the class consumers subclass, so every
  dedupe internal was a semver contract. The job keeps bind → resolve →
  dispatch; identity, locking and the `alreadyProcessed()` query move to a
  collaborator.

  **Upgrading:** `OPAQUE_EVENT_IDS`, `deduplicableEventId()`,
  `deduplicationLock()`, `deduplicationLockKey()` and `alreadyProcessed()` are
  gone from the job. Subclass `HubWebhookDeduplicator` and override the job's
  single `deduplicator()` hook instead. Lock keys and behaviour are unchanged.

- **`OAuthReturnUrl::fromConfigPath()` takes an origin string, not a `Request`.**
  It only ever needed `getSchemeAndHttpHost()`; taking it as a scalar keeps
  `Support/` free of `Illuminate\Http` and makes the class unit-testable.
  `IntegrationController` passes `$request->getSchemeAndHttpHost()`.

### Fixed

- **A failure the `failed()` hook could not record was swallowed.** Both early
  exits — `bindAccountContext()` returning false, and `resolveWebhookCall()`
  returning null — left `webhook_calls.exception` null, which is exactly the
  state `alreadyProcessed()` reads as "ran to completion", so Hub's redelivery
  was dropped. The 0.7.0 fix for that bug reproduced it in its own error paths.
  Both now log `hub.webhook.failure_unrecorded` at `error` level.

- **`routes/hub.php` carried a second, stale copy of the middleware default.**
  Its `config('hub.routes.middleware', ['api', 'auth:sanctum'])` fallback dropped
  the `throttle:60,1` that `config/hub.php` ships — and was unreachable anyway,
  since `packageBooted()` validates the same key before loading the file. The
  route file now reads the validated value and states where it is validated.

- **The published `webhook_calls` migration ships an index.**
  `alreadyProcessed()` filters `name` + `id` on every delivery, while holding the
  dedupe lock, on a table that only grows; the stub had no index at all.

  **Upgrading:** existing consumers already ran the old migration. Add the index
  yourself:

  ```php
  Schema::table('webhook_calls', function (Blueprint $table): void {
      $table->index(['name', 'id']);
  });
  ```

### Added

- `hub.routes.allow_unauthenticated` (env `EMEQ_HUB_ROUTES_ALLOW_UNAUTHENTICATED`,
  default `false`).

## [0.8.0] — 2026-08-12

Surface reduction ahead of 1.0. The package carried extension points that no
consumer uses and one dependency it never called; every one of them is a semver
contract, so they go before the contract freezes.

### Removed

- **`saloonphp/laravel-plugin` is no longer required.** The SDK never referenced
  it: no `Saloon\Laravel` import, no `Saloon::` call, in `src/` or `tests/`.
  `HubConnector` extends Saloon's framework-agnostic core and the tests mock with
  `Saloon\Http\Faking\MockClient`, also core. It was, on its own, the reason the
  package floor was Laravel 11+ (`illuminate/support: ^11.0 || ^12.39.0 ||
  ^13.0`). Consumers that want Saloon's Telescope/Pulse panels can require it
  themselves.

- **`hub.webhook.opaque_event_ids` config key** (added in 0.7.1). Hub is
  first-party, so its sentinel event ids are known at release time, not
  deployment time. The default moves to
  `ProcessHubWebhookJob::OPAQUE_EVENT_IDS`; subclasses override the constant.
  Behaviour is unchanged — `no-id` is still processed and never deduplicated.

### Changed

- **`ResolvesAccountDisplayName` is folded into `ResolvesAccountId`.** The
  interface held one nullable method, and every consumer implemented both on one
  class and bound it twice. `ResolvesAccountId` now declares
  `displayName(): ?string`; return null to let Hub name the account.
  `IntegrationController` takes one resolver instead of two.

  **Upgrading:** move `displayName()` onto your `ResolvesAccountId`
  implementation, drop `implements ResolvesAccountDisplayName`, and remove the
  second container binding.

## [0.7.1] — 2026-08-12

### Fixed

- **Webhook dedupe was scoped wider than one account.** The cache lock added in
  0.7.0 was keyed on config name + event id only, so on a cache store shared by
  all tenants one account's in-flight delivery blocked every other account's
  delivery of the same event id — and the loser is dropped as
  `concurrent_delivery`, not retried. `alreadyProcessed()` had the same gap on
  the single-DB default, where every account reads one `webhook_calls` table.
  Both halves are now keyed on account id + event id. Correctness no longer
  rests on Hub minting event ids that are unique across accounts.

  During a rolling deploy old and new workers take different lock keys, so a
  concurrent redelivery pair split across the two versions falls back to the
  unlocked check-then-act guard for the length of the rollout.

- **An event id that identifies nothing was treated as an identity.** Hub mints
  `X-Emeq-Event-Id` per delivery only when the partner supplies one; Snelstart's
  controller falls back to the literal string `no-id`, so unrelated events all
  arrive sharing it. The first one then swallowed every later one for that
  account, and concurrent ones contended on a single lock. Such values are now
  handled exactly like a missing header — processed, never deduplicated — and
  the list is configurable through `hub.webhook.opaque_event_ids` (default
  `['no-id']`) so a change on Hub's side does not need an SDK release. The raw
  header is still passed to the events for correlation.

## [0.7.0] — 2026-08-12

Architecture audit follow-up: all 23 findings from
`docs/reviews/2026-08-12-whole-repo-architecture-audit.md`, plus two blockers the
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
- `CONTEXT.md` and `docs/adr/` record the domain language, the layer rules and
  the two non-obvious decisions (provider-agnostic surface, publish-only
  migrations). Both are `export-ignore`d.

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
