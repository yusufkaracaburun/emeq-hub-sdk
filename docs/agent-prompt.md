# AI prompt — wire this SDK into your Laravel app

Paste the prompt below into your coding agent (Cursor, Claude Code, …) to
install and configure `emeq/hub-sdk` against your tenant model. Fill in the
`{…}` placeholders before pasting.

The prompt covers **install + config + account binding + one Hub-portal CTA**.
Integration BFF routes ship with the package (`EMEQ_HUB_ROUTES`). Do **not**
build per-provider connect UI — Hub's hosted `/connect` page is the single
source of truth.

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
   composer require emeq/hub-sdk:^0.9
2. Set in `.env` / `.env.example`:
   EMEQ_HUB_BASE={https://hub.emeq.nl}
   EMEQ_HUB_PAT=
   EMEQ_HUB_TIMEOUT=30
   EMEQ_HUB_WEBHOOK_SECRET=
   EMEQ_HUB_ROUTES=true
   EMEQ_HUB_ROUTES_PREFIX=api
   EMEQ_HUB_ROUTES_MIDDLEWARE={api,auth:sanctum}
   EMEQ_HUB_OAUTH_RETURN_PATH={/settings/integrations?oauth=1}
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

DO NOT
- Browser / direct calls to the Hub (PAT stays server-side)
- Install emeq/exact-api or other partner SDKs in this consumer app
- Build per-partner pass-through wrappers or per-provider connect UI
- Take X-Account-Id / account_external_id from the client request
- Re-implement connect-session / list routes in my app (SDK owns those)

DONE WHEN
- composer show emeq/hub-sdk works
- ResolvesAccountId is bound
- Package routes respond under my auth middleware
- Feature/smoke test: connect-session (MockClient) proves account id is derived
  server-side; optional list test ignores a spoofed request header
```
