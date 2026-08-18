# AI prompt — wire this SDK into your Laravel app

Paste the prompt below into your coding agent (Cursor, Claude Code, …) to
install and configure `emeq/hub-sdk` against your tenant model. Fill in the
`{…}` placeholders before pasting.

The prompt covers **install + config + account binding + one Hub-portal CTA**.
Integration BFF routes ship with the package (`EMEQ_HUB_ROUTES`). Do **not**
build per-provider connect UI — Hub's hosted `/connect` page is the single
source of truth.

Booking documents into the bookkeeping is **step 11 and optional** — skip it in
apps that only connect and show status. Where it applies, it is the step most
likely to be re-implemented badly: the retry rules are not guessable, and
guessing wrong posts duplicates into a real administration.

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
- Booking documents into the bookkeeping: {yes / no}
- If yes — models to book: {App\Invoice, App\Transaction, … and the column that
  holds each one's stable external id, commonly `uuid`}
- If yes — the database those models live on: {default / tenant / …}

DO THIS (in order)
1. Add Composer VCS repo and require:
   composer config repositories.emeq-hub-sdk vcs https://github.com/yusufkaracaburun/emeq-hub-sdk.git
   composer require emeq/hub-sdk:^0.24
2. Set in `.env` / `.env.example`:
   EMEQ_HUB_BASE={https://hub.emeq.nl}
   EMEQ_HUB_PAT=
   EMEQ_HUB_TIMEOUT=30
   EMEQ_HUB_WEBHOOK_SECRET=
   EMEQ_HUB_WEBHOOK_LOCK_STORE={redis — must support atomic locks}
   EMEQ_HUB_ROUTES=true
   EMEQ_HUB_ROUTES_PREFIX=api
   EMEQ_HUB_ROUTES_MIDDLEWARE={api,auth:sanctum}
   EMEQ_HUB_OAUTH_RETURN_PATH={/settings/integrations?oauth=1}
   # Only when booking is "yes" in CONTEXT:
   EMEQ_HUB_BOOKING_CONNECTION={empty for default}
   EMEQ_HUB_BOOKING_LOCK_STORE={redis — must support atomic locks}
3. Run `php artisan hub:install` (publishes hub-config + hub-migrations).
4. Implement `Emeq\HubSdk\Contracts\ResolvesAccountId` in my app
   (e.g. `App\Integrations\Hub\HubAccountIdResolver`) mapping the current
   tenant to Hub `external_id` per CONTEXT above — server-side only.
   `displayName()` may return null.
5. Bind that class in `AppServiceProvider::register()` to
   `ResolvesAccountId::class`.
6. Explicitly set `EMEQ_HUB_ROUTES=true` (package default is false). Middleware
   must be non-empty. Do NOT hand-roll Hub HTTP clients or duplicate the
   package BFF routes.
7. UI: one "Manage integrations" button that POSTs
   `/{prefix}/integrations/connect-session` and redirects to `response.url`.
   Users connect/disconnect on Hub — do NOT build per-provider OAuth buttons
   or revoke flows in my app.
8. Optional: GET `/{prefix}/integrations` for a read-only status strip.
   Do not store tokens, connection state, or provider credentials in my DB.
9. Inbound webhooks (if Hub fans out to this app): bind
   `ResolvesWebhookAccount`, register `Route::webhooks('webhooks/emeq-hub',
   'emeq-hub')` + CSRF except, migrate `webhook_calls`, listen for
   `HubConnectionRevoked` (and related Events\*). Webhook profile/job/secret
   live under `config('hub.webhook')` — do not publish a separate
   `webhook-client.php` for Hub, and do not invent a custom HMAC endpoint.
10. Errors: HubException subclasses map to JSON on the BFF routes; when calling
   Hub yourself, rethrow or map and log `requestId` when set.
   The BFF routes never pass a Hub status code through: a Hub 429 answers `503`
   with `Retry-After`, every other upstream failure answers `502`, and a local
   configuration error answers `503`. Read what Hub said from `hub_status` in
   the body — do NOT branch on the HTTP status to decide the user is logged out.
   A Hub `401` means my PAT was rejected, not that this user lost their session.
11. ONLY IF booking is "yes" in CONTEXT. Read the README's "Booking documents"
   section before writing anything. Write exactly these three, and nothing that
   duplicates what they call into:
   a. A mapper per model → canonical document (`type`, `external_id`, `number`,
      `currency`, `issue_date`, `party`, `lines`). `external_id` is the model's
      stable id — never a fresh uuid. Throw
      `Emeq\HubSdk\Exceptions\DocumentNotBookable` for anything that can never
      be sent (draft, cancelled, no party, no date, a VAT rate that does not
      reconcile). Use `Emeq\HubSdk\Support\DutchVatNumber::normalise()` for a
      party's VAT number; send nothing rather than something unverified.
   b. `Emeq\HubSdk\Booking\Contracts\ResolvesBookableDocument` — find the record,
      authorise it (throw `Exceptions\DocumentNotAuthorized` on refusal), map it,
      return a `Booking\BookableDocument` with the attachment renderer as a
      closure. Then call `Booking\BookingRunner` (`bookOne` / `checkOne` /
      `book` / `check`) from my controllers.
   c. `Emeq\HubSdk\Backlog\Contracts\ProvidesBacklogSources` if I need a
      "not booked yet" screen — one query per module answering exactly
      `ProvidesBacklogSources::COLUMNS`, excluding posted documents with
      `Backlog\PostedDocuments::excluding()`. Then call
      `Backlog\BacklogRepository` for paging and the summary.
   Publish and run the `hub_documents` migration on the database that holds
   those models — the backlog joins the two, so they cannot live on separate
   connections. Set `EMEQ_HUB_BOOKING_CONNECTION` when it is not the default and
   `EMEQ_HUB_BOOKING_LOCK_STORE` to a store that supports atomic locks.

12. Verify the wiring with `php artisan hub:doctor` (add `--ping` to also call
   Hub with the configured PAT) and fix every `fail` before calling this done.
   Warnings are only acceptable for features CONTEXT says this app does not use.

DO NOT
- Browser / direct calls to the Hub (PAT stays server-side)
- Install emeq/exact-api or other partner SDKs in this consumer app
- Build per-partner pass-through wrappers or per-provider connect UI
- Take X-Account-Id / account_external_id from the client request
- Re-implement connect-session / list routes in my app (SDK owns those)
- Write my own booking-state table, retry loop, idempotency key, concurrency
  lock, or "already booked?" check — `Booking\DocumentBooker` and the
  `hub_documents` ledger own all five, and the rules are not guessable
- Record a row for a 429, a 5xx or a dropped connection. No row and a row
  saying failed mean different things to the next run
- Resend a document whose ledger row says `posted` or `unknown`

DONE WHEN
- composer show emeq/hub-sdk works
- ResolvesAccountId is bound
- Package routes respond under my auth middleware
- Feature/smoke test: connect-session proves account id is derived server-side;
  optional list test ignores a spoofed request header. Mock with
  `MockClient::global([...])` keyed on URL patterns and destroy it in afterEach —
  `Saloon::fake()` does not exist (no saloonphp/laravel-plugin)
- Accounting tests mock with `Emeq\HubSdk\Testing\HubMock` (captured real Hub
  responses), not with hand-written payloads
- Any createDocument call passes a key derived from the document's external_id,
  not a fresh uuid
- If booking: no class in my app writes to `hub_documents` except through
  `DocumentBooker`, and `grep` finds no second retry/idempotency/lock helper
- If booking: a test proves a document whose ledger row is `posted` sends
  nothing on a second attempt, and that a 5xx leaves no row behind
```
