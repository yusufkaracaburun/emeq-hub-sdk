# Architecture audit — whole repo — 2026-08-12

**Scope:** whole repo (`src/`, `routes/`, `config/`, `database/`, `tests/`) — working tree, incl. uncommitted changes
**Stack:** PHP 8.3+ · Laravel 13 (`illuminate/*`) package · Saloon 4 · spatie/laravel-package-tools · spatie/laravel-webhook-client 3 · Orchestra Testbench 11 · Pest 3/4 · Larastan 3
**Extensions loaded:** audit-architecture-laravel (strict, floor=🟡, api=🟠) — loaded manually; `detect-tooling.sh` reported `frameworks: []` because the package requires `illuminate/*` components rather than `laravel/framework`
**Laravel mode:** `full-stack` per helper — **misdetected**; this is a headless SDK with no views. `api-only` heuristics L14/L16/L18 were evaluated manually and are N/A (the package exposes 2 opt-in BFF routes, not an API surface).
**Tools ingested:** Larastan ✓ (level 5, no errors) · `composer outdated` ✗ · `php artisan about` ✓ (via the dev-only `artisan` shim)
**Files walked:** 62 · **LOC:** 2 837 (src 2 136 / tests 701) · **Audit duration:** ~25 min
**Auditor:** /ai:audit-architecture (ai-kit v1.47.1)

> **Status 2026-08-12:** all 🔴/🟠 rows fixed and released as 0.7.0 (commits
> `20beb3a`…`afc161c`). The 🟡 rows are open. Fixing this also surfaced a blocker
> the audit missed — see the note under the rolling table.

## Summary

- 🔴 1 · 🟠 9 · 🟡 13 findings (23 total) across 9 dimensions
- Top 3 themes:
  1. **A failed webhook job is indistinguishable from a processed one.** The dedupe guard filters on `webhook_calls.exception`, but nothing writes that column from the queued path — so a crashed webhook silently blocks its own redelivery. Single 🔴.
  2. **One rule, three implementations.** Account resolution, error-envelope decoding and event-id extraction are each encoded in 2–3 places with *different* failure behaviour. The divergence, not the repetition, is the defect.
  3. **The `ProcessHubWebhookJob` / `SerializesHubWebhookByIds` pair is held together by docblocks.** Parent sniffs an optional trait property; the trait ships a constructor that only works on a subclass; forgetting one override degrades silently instead of failing.

No architecture is declared anywhere (no `CONTEXT.md`, no `docs/adr/`, no `docs/agents/architecture.md`), so dimension 7 flags the absence rather than inventing rules.

---

## 1. Design patterns

Covered, no standalone findings. The Resource/Request split over Saloon is idiomatic and earns its keep; nothing is hand-rolled that the framework already provides. The one pattern-level concern — two competing error-mapping mechanisms — has its root cause in duplication and is filed as **C1** under dimension 3.

## 2. SOLID

**B1 · 🟠 · `src/Webhooks/ProcessHubWebhookJob.php:90` — parent job introspects an optional trait's property (DIP + LSP)**

```php
if (property_exists($this, 'accountId') && is_string($this->accountId) && ...)
```

`accountId` is declared by `SerializesHubWebhookByIds`, a trait the base class knows nothing about by type. The base class reaches *up* into optional subclass state; `property_exists()` is the seam where an interface should be. Any consumer who names a property `accountId` for unrelated reasons silently changes the job's behaviour.
**Fix direction:** add `protected function serializedAccountId(): ?string { return null; }` to the base and override it in the trait. Same-shaped fix removes the `property_exists` call entirely.
**Cross-ref:** the reverse half of this coupling is **F1** (dimension 6) — same root cause, filed once.

**B2 · 🟡 · `src/Http/Controllers/IntegrationController.php:51,52,72,76` — container-resolved collaborators, no injection seam**

The controller reaches for `app(ResolvesAccountId::class)`, `app()->bound(...)`, `config()` and the `Hub` facade inside methods. It cannot be exercised without a booted container, which is why every test for it is a Feature test. Constructor injection of `ResolvesAccountId` + `Hub` would make it unit-testable and make the dependency list readable.
**Counterweight:** this is idiomatic Laravel controller style and the class is marked `@internal`. 🟡, not 🟠.

## 3. DRY (knowledge-duplication)

**C1 · 🟠 · `src/Http/Middleware/MapHubErrors.php:21-31` ≈ `src/Http/HubConnector.php:70-80` — error-envelope decoding duplicated verbatim**

Both blocks do: `try { json() } → is_array? → catch → ['message' => body()] → HubException::fromEnvelope(...)`. Identical knowledge, two copies.

Worse, they are not both live. Saloon reaches `Connector::getRequestException()` only from `Response::toException()` (`vendor/saloonphp/saloon/src/Http/Response.php:459`), i.e. via `$response->throw()` or the `AlwaysThrowOnErrors` trait — neither of which this connector uses. `MapHubErrors` throws first on every failed response, so the connector hook is dead on the normal `send()` path and survives only for consumers who take the `Hub::connector()` escape hatch and call `->throw()` themselves.
**Fix direction:** keep one decoder (a private static on `HubException`, e.g. `fromResponse(Response $r)`) and have both call sites delegate to it. Then decide deliberately whether `getRequestException()` stays as the escape-hatch path.

**C2 · 🟠 · three different failure behaviours for "no account id"**

| Site | Behaviour on unresolvable account |
| --- | --- |
| `src/Support/ResolvesAccountContext.php:28` | throws `InvalidArgumentException` (SPL) |
| `src/Resources/Integrations.php:29` | silently passes `null` to the request |
| `src/Http/Controllers/IntegrationController.php:73` | throws `MissingConfigurationException` |

One domain rule, three encodings. The SPL exception is the sharp edge: consumers who wrap SDK calls in `catch (HubException $e)` — which is what the README teaches — will not catch it, so a missing binding surfaces as an uncaught 500 instead of a typed SDK error.
**Fix direction:** `ResolvesAccountContext` throws `MissingConfigurationException::missingAccountResolver()`; `Integrations::list()` uses the same trait so "no account" means the same thing everywhere (or documents explicitly why the catalog is account-optional).

**C3 · 🟡 · event-id extraction encoded twice, in two languages**

`src/Webhooks/HubWebhookHeaders.php:39-54` implements case-insensitive, scalar-or-array header lookup in PHP. `src/Webhooks/ProcessHubWebhookJob.php:181-186` re-implements the same knowledge as three OR'd SQL predicates (`headers->k`, `headers->k[0]`, `whereJsonContains`). Both are currently correct — Symfony lowercases header keys and stores array values — but they drift independently.
**Fix direction:** one `HubWebhookHeaders::whereEventId(Builder $q, string $eventId)` that owns the storage shape.

**C4 · 🟡 · six Resource classes repeat identical construction**

`Accounts`, `Integrations`, `OAuth`, `ConnectSessions`, `Connections`, `Accounting` each redeclare `__construct(HubConnector $connector, ?ResolvesAccountId $resolver = null)` plus a trait `use`. The knowledge "a resource is a connector + an optional account resolver" lives six times.
**Fix direction:** `abstract class Resource` holding the constructor and `ResolvesAccountContext`. Note this is the *only* duplication among the resources — the seven `get()` wrappers in `Accounting` (`documents()`, `taxCodes()`, …) are named public API, not duplication, and should stay.

## 4. YAGNI / dead-code

**D1 · 🟡 · `src/Support/OAuthReturnUrl.php:40-47` — unreachable second `//` guard**

Verified by exhaustive walk over the input classes: any value starting with `//` throws at the first guard (line 24-27); a value not starting with `/` gains exactly one leading slash. No input reaches line 40. Test inputs `'//evil.example/phish'` and `'\evil.example'` both terminate at guard 1.
**Fix direction:** delete lines 40-47, or replace both guards with one normalise-then-validate step.

**D2 · 🟡 · `src/Http/HubConnector.php:83-86` — empty `boot()` override**

Overrides a no-op parent to add a comment. Delete; the comment belongs on the request classes that actually set account headers.

**D3 · 🟡 · `src/Http/Controllers/IntegrationController.php:32` — guard disguised as a getter**

`$this->accountId();` is called for its throw-side-effect and the return value dropped. Readers cannot tell it is load-bearing.
**Fix direction:** rename to `assertAccountResolverBound()`, or pass the value into the call it guards.

## 5. Naming + comment-drift

**E1 · 🟡 · `src/Facades/Hub.php:8,26,32` — `@see HubManager` names a class that does not exist**

`HubManager` is a local alias for `Emeq\HubSdk\Hub`, introduced to dodge the facade's own class name. The `@see` tag and the accessor both read as a reference to a real class; grepping the repo for `HubManager` finds only the facade. (Checked: the facade resolves correctly — this is a readability defect, not a bug.)

**E2 · 🟡 · `src/Http/Middleware/MapHubErrors.php:12-14` — docblock describes the opposite of what happens**

"Saloon already maps via `Connector::getRequestException` when throw is used; this middleware covers `send()` … for clarity." In practice the middleware *pre-empts* the connector hook on every failed response. See **C1**.

**E3 · 🟡 · `src/Http/HubConnector.php:73-75` — annotation asserts what the next line re-checks**

`/** @var array<string, mixed> $decoded */` immediately above `is_array($decoded) ? … : []`. Either the annotation is a lie or the check is dead. Same shape at `src/Http/Middleware/MapHubErrors.php:24-26`.

## 6. Coupling / cohesion (local / structural)

**F1 · 🟠 · `src/Webhooks/SerializesHubWebhookByIds.php:21-28` — trait ships a constructor that calls `parent::__construct()`**

The trait is only valid when mixed into a `ProcessWebhookJob` subclass; nothing but a docblock enforces it. Combined with **B1** (the parent sniffing this trait's property), parent and trait each depend on the other's internals with no type-level contract between them.
**Fix direction:** the hook method from **B1** breaks the cycle in one direction; declaring an `abstract` base or an interface the trait requires closes the other.

**F2 · 🟠 · `src/Webhooks/ProcessHubWebhookJob.php:116-119` + `src/Webhooks/SerializesHubWebhookByIds.php:68-69` — hidden temporal coupling degrades silently**

`__unserialize()` rebuilds a hollow `new WebhookCall(['id' => N])` with `exists = true`. The base `resolveWebhookCall()` returns that hollow model as-is. A consumer who adopts the trait (multi-DB) but forgets to override `resolveWebhookCall()` gets `payload === null` → the job logs `invalid_payload_in_job` at `info` level and returns successfully. **Dropped webhooks that look like a clean run.**
**Fix direction:** make the base `resolveWebhookCall()` detect the hollow model (`$call->payload === null && $call->exists`) and throw, or make the trait abstract-require the override.

**F3 · 🟡 · `src/Webhooks/SpatieWebhookClientConfig.php:27` — global `config()` fallback inside a pure builder**

`$signingSecret ?? (string) config('hub.webhook.secret', '')`. Every caller (`HubServiceProvider:90`) already passes the secret, so the fallback only fires when the class is used standalone — precisely the case where the container may not be booted. Hidden global state in an otherwise pure static factory.
**Fix direction:** make `$signingSecret` a required `string`.

## 7. Layering / dependency direction

**G1 · 🟡 · no architecture declared**

No `CONTEXT.md`, no `docs/adr/`, no `docs/agents/architecture.md`. Per the audit rules this is flagged, not invented: there are no documented layering rules to violate. For a package this size the namespace layout (`Contracts` / `Http` / `Resources` / `Support` / `Webhooks` / `Exceptions` / `Events`) is self-evident and consistently followed — the gap is that nothing records *why*, which matters most for the two non-obvious decisions (provider-agnosticism; publish-only migration).

One direction issue inside that layout: `src/Support/OAuthReturnUrl.php:8` imports `Illuminate\Http\Request` although `Support/` otherwise holds framework-free helpers (`HubRouteMiddleware`, `DecodesHubJson`). It needs exactly one thing from the request — `getSchemeAndHttpHost()`.
**Fix direction:** take `string $origin` and let the controller supply it. Makes the class trivially unit-testable.

## 8. Error handling / failure modes

**H1 · 🔴 · `src/Webhooks/ProcessHubWebhookJob.php:177-188` — a crashed webhook silently suppresses its own redelivery (L8)**

Confirmed chain:

1. `alreadyProcessed()` treats any earlier row with `exception IS NULL` as "already handled".
2. `webhook_calls.exception` is written in exactly one place: `Spatie\WebhookClient\WebhookProcessor:60`, on the **synchronous request path** (profile / store / dispatch).
3. `ProcessHubWebhookJob` declares no `failed()`, no `$tries`, no `$backoff`, and never calls `saveException()` — verified: zero matches across `src/Webhooks/`.
4. Therefore when `handle()` throws, the row keeps `exception = NULL`.
5. Hub redelivers the same `X-Emeq-Event-Id`; the new job finds the older, *failed* row, sees `exception IS NULL`, and returns at the `hub.webhook.deduplicated` branch.

Net effect: **the one case retries exist for is the case dedupe eats.** The failure is logged at `info` and the queue reports success.
**Fix direction:** add `public function failed(Throwable $e): void { $this->webhookCall->saveException($e); }` to `ProcessHubWebhookJob` (this is the exact gap L8 targets), and set an explicit `$tries` / `$backoff` so package consumers inherit a defined retry policy instead of the host's queue default.

**H2 · 🟠 · `src/Support/OAuthReturnUrl.php:28-33,41-46` — consumer misconfiguration reported as end-user validation error**

A malformed `hub.oauth.return_path` in the consumer's `config/hub.php` throws `ValidationException(status: 422, category: 'VALIDATION_ERROR')`. `IntegrationController::connectSession()` catches it as a `HubException` and returns 422 to the *API caller* — who cannot fix a server-side config value, and whose client will read it as "your input was wrong".
**Fix direction:** `MissingConfigurationException` (the class already exists, and this is exactly its job). Better still, validate the path once at provider boot so a bad deploy fails immediately rather than per request.

**H3 · 🟠 · `src/Webhooks/ProcessHubWebhookJob.php:55-63` — dedupe is check-then-act with no lock**

Two workers processing concurrent redeliveries of the same `event_id` both run `alreadyProcessed()`, both see no prior row, both dispatch `HubWebhookReceived`. There is no unique constraint, no `lockForUpdate()`, no cache lock. The migration stub carries no index at all (`database/migrations/create_webhook_calls_table.php.stub`), so nothing at the storage layer prevents it either.
**Fix direction:** `Cache::lock("hub-webhook:{$eventId}")` around the guard, or a unique index on the extracted event id. Consumers already run this table per-tenant, so a package-level default matters.

**H4 · 🟠 · `config/hub.php:71` + `routes/hub.php` — shipped default registers unthrottled routes (L13)**

The package's own default middleware is `api,auth:sanctum`. In Laravel 11+ the `api` group carries no rate limiter unless the host opts in via `bootstrap/app.php`. `HubRouteMiddleware::assertNotEmpty()` correctly refuses *unauthenticated* routes but says nothing about throttling — so a consumer who flips `EMEQ_HUB_ROUTES=true` gets an authenticated endpoint that fans out to the Hub API on every call, with no ceiling.
**Fix direction:** default to `api,auth:sanctum,throttle:60,1` and document the override. Cheap, and it makes the safe path the default path.

## 9. Type safety / contract clarity

**I1 · 🟠 · `src/Webhooks/HubWebhookEvent.php` — closed set modelled as loose strings**

Nine `public const` strings on a final class, consumed via `$envelope->event === HubWebhookEvent::CONNECTION_REVOKED` where `$event` is a bare `string`. PHP 8.3 is the floor, so a backed enum is available. Consumers comparing against a typo get silent no-match, and nothing narrows the type at the boundary.
**Fix direction:** `enum HubWebhookEvent: string` + `HubWebhookEvent::tryFrom($raw) ?? HubWebhookEvent::Unmapped` in `HubWebhookEnvelope::tryFromArray()`. This preserves the forward-compatibility the current design buys (unknown Hub events must not break the SDK) *and* closes the set. **Breaking change for consumers — schedule for 0.7.**

**I2 · 🟡 · Resource return types are un-narrowed unions**

`array<string, mixed>|list<array<string, mixed>>` is the declared return of six `Accounting` methods; every consumer must re-narrow. Returning raw arrays instead of DTOs is a deliberate provider-agnosticism choice and is not itself a finding — the union is. Related muddle at `src/Resources/Accounting.php:116,124,132`: `$this->json($this->get(...))` re-validates a value `get()` already guaranteed to be an array, and `get():177-179` calls `json()` on a value it just proved is *not* an array purely to trigger the throw.
**Fix direction:** split `get()` into `getObject(): array<string,mixed>` and `getList(): list<...>` so each caller has one shape.

**I3 · 🟡 · `phpstan.neon.dist:2` — Larastan level 5 of 9**

Level 5 misses exactly the class of defect this audit found by hand (nullable returns, array-shape drift). Larastan currently reports no errors, so headroom exists to climb.
**Fix direction:** raise one level at a time; level 6 (missing iterable value types) should be nearly free given the existing annotations.

---

## Appendix — notes outside audit scope

- **Missing index on `webhook_calls`** (`database/migrations/create_webhook_calls_table.php.stub`). The dedupe query filters `name` + `id` + `exception` + a JSON path on a table that only grows. Performance is explicitly out of this audit's scope, but the package ships both the query and the migration, so the mismatch is the package's to own. Spatie's upstream migration has no index either.
- **`a//b` normalises to `/a//b`** in `OAuthReturnUrl`. Same-host relative path, so not protocol-relative escape — routed to `/ai:review` security pass rather than scored here.
- **`HubWebhookProfile` requires `ResolvesWebhookAccount` via constructor injection.** An unbound contract surfaces as a container `BindingResolutionException` (500) at webhook time rather than a typed `MissingConfigurationException`. Install-command step 3 documents the binding.
- **Stack detection false negative.** `bin/detect-tooling.sh` keys on `laravel/framework`; packages depending on `illuminate/*` components report `frameworks: []` and get no extension. Worth reporting upstream via `/ai:feedback`.

## Tech-debt rolling table

| ID | Finding | Severity | Fix direction | Suggested owner |
|----|---------|----------|---------------|-----------------|
| H1 | ✅ fixed — Crashed webhook job suppresses its own redelivery (`exception` never written) | 🔴 | Add `failed()` → `saveException()`; set explicit `$tries`/`$backoff` | webhooks |
| C2 | ✅ fixed — "No account id" has three different behaviours (SPL vs typed vs silent null) | 🟠 | One rule in `ResolvesAccountContext`, throwing `MissingConfigurationException` | resources |
| H2 | ✅ fixed — Consumer misconfiguration returned to API callers as 422 validation error | 🟠 | `MissingConfigurationException`; validate at boot | support |
| H3 | ✅ fixed — Dedupe check-then-act, no lock or unique constraint | 🟠 | `Cache::lock` on event id, or unique index | webhooks |
| H4 | ✅ fixed — Shipped route default has auth but no `throttle:` | 🟠 | Default `api,auth:sanctum,throttle:60,1` | routes/config |
| C1 | ✅ fixed — Error-envelope decoding duplicated; connector hook unreachable on `send()` | 🟠 | One decoder on `HubException`; decide the escape-hatch path deliberately | http |
| B1 | ✅ fixed — Base job sniffs optional trait property via `property_exists()` | 🟠 | `serializedAccountId()` hook on the base | webhooks |
| F1 | ✅ fixed — Trait constructor calls `parent::__construct()`; parent↔trait cycle | 🟠 | Interface/abstract contract between the two | webhooks |
| F2 | ✅ fixed — Hollow `WebhookCall` after unserialize degrades to a silent skip | 🟠 | Detect hollow model in `resolveWebhookCall()` and throw | webhooks |
| I1 | ✅ fixed — `HubWebhookEvent` is string consts, not a backed enum | 🟠 | `enum … : string` + `tryFrom() ?? Unmapped` (breaking → 0.7) | webhooks |
| C3 | Event-id extraction encoded in both PHP and SQL | 🟡 | `HubWebhookHeaders::whereEventId()` owns the storage shape | webhooks |
| C4 | Six resources repeat the same constructor + trait wiring | 🟡 | `abstract class Resource` | resources |
| B2 | Controller resolves collaborators from the container, no seam | 🟡 | Constructor injection | http |
| D1 | Unreachable second `//` guard | 🟡 | Delete lines 40-47 | support |
| D2 | Empty `boot()` override | 🟡 | Delete | http |
| D3 | `accountId()` called for its side effect, return dropped | 🟡 | Rename to `assertAccountResolverBound()` | http |
| E1 | `@see HubManager` names a non-existent class | 🟡 | Reference `Emeq\HubSdk\Hub` | facades |
| E2 | `MapHubErrors` docblock describes the inverse of the behaviour | 🟡 | Rewrite alongside C1 | http |
| E3 | `@var` annotation asserts what the next line re-checks | 🟡 | Drop the annotation or the check | http |
| F3 | `config()` fallback inside a pure static builder | 🟡 | Require `string $signingSecret` | webhooks |
| G1 | No declared architecture (no CONTEXT.md / ADRs) | 🟡 | Record the two non-obvious decisions as ADRs | repo |
| I2 | Un-narrowed union return types; double-validated payloads | 🟡 | Split `get()` into object/list variants | resources |
| I3 | Larastan level 5 of 9 | 🟡 | Climb one level at a time | tooling |

## What the audit missed

Writing the regression test for **C2** loaded `GetAccountingRequest` for the first
time in the suite and PHP fatalled:

```
Cannot redeclare non-readonly property Saloon\Http\Request::$query
as readonly Emeq\HubSdk\Http\Request\Accounting\GetAccountingRequest::$query
```

Nine public methods — every `Hub::accounting()` GET — were unusable. Two things
hid it, and both are audit lessons rather than code findings:

1. The file was listed in `phpstan.neon.dist` `excludePaths`. This audit read that
   exclusion as scoped noise-suppression and did not ask *why* a single file needed
   excluding. **A static-analysis exclusion is a claim that something is fine; treat
   it as a finding until proven otherwise.**
2. No test ever constructed the class, so the whole accounting surface was
   uncovered. Dimension coverage says nothing about *execution* coverage.

Fixed in `72a8f4d`; the exclusion is gone and the suite is clean without it.
