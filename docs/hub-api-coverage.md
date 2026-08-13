# Hub API coverage

Which `/v1/*` endpoints of emeq-hub this SDK wraps, and which it does not yet.
This doubles as a progress doc: every ⬜ row is backlog.

**As of** 2026-08-13 · **Hub** `898cab7` · **SDK** `8f0485d`

The Hub publishes its own OpenAPI at <https://hub.emeq.nl/docs/api>. That is the
server side and says nothing about what this SDK supports — hence this document.

## Contents

- [Refreshing it](#refreshing-it)
- [Legend](#legend)
- [Status](#status)
- [Platform](#platform)
- [Accounts and connections](#accounts-and-connections)
- [Accounting](#accounting)
- [Exact pass-through](#exact-pass-through)
- [Snelstart pass-through](#snelstart-pass-through)
- [Mollie — payments](#mollie--payments)
- [Mollie — Connect](#mollie--connect)
- [Account subscriptions](#account-subscriptions)
- [Billing](#billing)
- [Reaching what is not wrapped](#reaching-what-is-not-wrapped)
- [Suggested order](#suggested-order)

## Refreshing it

```bash
# Hub side: every route
docker compose exec app php artisan route:list --path=v1

# SDK side: what we actually call
grep -rn -A 3 'function resolveEndpoint' src/Http/Request
```

## Legend

| | |
|---|---|
| ✅ | wrapped, reachable through a resource method |
| ⬜ | not wrapped — reachable through `Hub::connector()` |
| — | deliberately out of scope (not client surface) |

## Status

| Area | Endpoints | Wrapped |
|---|---:|---:|
| Platform | 1 | 0 |
| Accounts and connections | 11 | 5 |
| Accounting | 13 | 13 |
| Exact pass-through | 5 | 0 |
| Snelstart pass-through | 1 | 0 |
| Mollie — payments | 20 | 0 |
| Mollie — Connect | 9 | 0 |
| Account subscriptions | 6 | 0 |
| Billing | 1 | 0 |
| **Total (consumer surface)** | **65** | **19** |

Outside those 65 sit two OAuth callbacks (browser redirects) and two
`/v1/admin/billing/*` routes (Emeq-internal, behind `emeq.admin`).

## Platform

| | Endpoint | Ability | SDK | |
|---|---|---|---|---|
| GET | `/v1/ping` | — | | ⬜ |

## Accounts and connections

The order a consumer app walks these: create the account → show available
providers → connect session or OAuth init → read connection state.

| | Endpoint | Ability | SDK | |
|---|---|---|---|---|
| POST | `/v1/accounts` | — | `accounts()->create()` | ✅ |
| GET | `/v1/integrations` | `integrations:manage` \| `consumer:manage-accounts` | `integrations()->list()` | ✅ |
| POST | `/v1/connect-sessions` | `integrations:manage` \| `consumer:manage-accounts` | `connectSessions()->create()` | ✅ |
| POST | `/v1/oauth/{provider}/init` | `integrations:manage` | `oauth()->init()` | ✅ |
| POST | `/v1/oauth/exact/init` | `integrations:manage` \| `exact:write` | via `oauth()->init('exact')` | ✅ |
| POST | `/v1/oauth/mollie/init` | `integrations:manage` \| `mollie:write` | via `oauth()->init('mollie')` | ✅ |
| POST | `/v1/connections` | — | | ⬜ |
| GET | `/v1/connections/{connection}` | — | `connections()->get()` | ✅ |
| DELETE | `/v1/connections/{connection}` | — | `connections()->delete()` | ✅ |
| GET | `/v1/oauth/exact/callback` | public | | — |
| GET | `/v1/oauth/mollie/callback` | public | | — |

`POST /v1/connections` is the only lifecycle route without a wrapper. OAuth
providers do not need it — `init` creates the row itself — so it only matters for
providers without an OAuth flow (Snelstart, clientkey).

## Accounting

Provider-independent, and fully covered. Abilities are enforced in the
controllers rather than on the route: reads want `accounting:read` (or
`:write`), writes want `accounting:write`.

| | Endpoint | Ability | SDK | |
|---|---|---|---|---|
| POST | `/v1/accounting/documents` | `accounting:write` | `accounting()->createDocument()` | ✅ |
| POST | `/v1/accounting/documents/validate` | `accounting:read` | `accounting()->validateDocument()` | ✅ |
| GET | `/v1/accounting/documents` | `accounting:read` | `accounting()->documents()` | ✅ |
| GET | `/v1/accounting/bank-statements` | `accounting:read` | `accounting()->bankStatements()` | ✅ |
| GET | `/v1/accounting/ledger-accounts` | `accounting:read` | `accounting()->ledgerAccounts()` | ✅ |
| GET | `/v1/accounting/tax-codes` | `accounting:read` | `accounting()->taxCodes()` | ✅ |
| GET | `/v1/accounting/customers` | `accounting:read` | `accounting()->customers()` | ✅ |
| GET | `/v1/accounting/suppliers` | `accounting:read` | `accounting()->suppliers()` | ✅ |
| GET | `/v1/accounting/capabilities` | `accounting:read` | `accounting()->capabilities()` | ✅ |
| GET | `/v1/accounting/reference-data` | `accounting:read` | `accounting()->referenceData()` | ✅ |
| GET | `/v1/accounting/mapping` | `accounting:read` | `accounting()->mapping()` | ✅ |
| PUT | `/v1/accounting/mapping` | `accounting:write` | `accounting()->updateMapping()` | ✅ |
| POST | `/v1/accounting/sync` | `accounting:write` | `accounting()->sync()` | ✅ |

`POST /v1/accounting/documents` requires an `Idempotency-Key`, which is why
`createDocument()` takes it as a required argument. The six list endpoints
paginate by cursor and return an `AccountingPage`.

## Exact pass-through

Provider-specific and division-aware. All of it behind the `exact` feature flag.

| | Endpoint | Ability | SDK | |
|---|---|---|---|---|
| GET | `/v1/exact/gl-accounts` | `exact:read` \| `exact:write` | | ⬜ |
| GET | `/v1/exact/vat-codes` | `exact:read` \| `exact:write` | | ⬜ |
| GET | `/v1/exact/relations` | `exact:read` \| `exact:write` | | ⬜ |
| GET | `/v1/exact/journals` | `exact:read` \| `exact:write` | | ⬜ |
| GET | `/v1/exact/{path}` | `exact:read` \| `exact:write` | | ⬜ |

The first four overlap functionally with `ledger-accounts`, `tax-codes` and
`customers`/`suppliers` from the accounting layer. Anything that wants to stay
provider-independent should use those; this route exists for Exact-specific
fields.

## Snelstart pass-through

| | Endpoint | Ability | SDK | |
|---|---|---|---|---|
| ANY | `/v1/snelstart/{path}` | — | | ⬜ |

## Mollie — payments

Behind the `mollie` feature flag.

| | Endpoint | SDK | |
|---|---|---|---|
| POST | `/v1/mollie/payments` | | ⬜ |
| GET | `/v1/mollie/payments/{id}` | | ⬜ |
| DELETE | `/v1/mollie/payments/{id}` | | ⬜ |
| POST | `/v1/mollie/payments/{id}/refunds` | | ⬜ |
| GET | `/v1/mollie/payments/{id}/refunds` | | ⬜ |
| GET | `/v1/mollie/refunds/{id}` | | ⬜ |
| GET | `/v1/mollie/payment-methods` | | ⬜ |
| GET | `/v1/mollie/payment-links` | | ⬜ |
| POST | `/v1/mollie/payment-links` | | ⬜ |
| GET | `/v1/mollie/payment-links/{id}` | | ⬜ |
| GET | `/v1/mollie/customers` | | ⬜ |
| POST | `/v1/mollie/customers` | | ⬜ |
| GET | `/v1/mollie/customers/{id}` | | ⬜ |
| GET | `/v1/mollie/customers/{id}/mandates` | | ⬜ |
| GET | `/v1/mollie/customers/{id}/mandates/{mandate_id}` | | ⬜ |
| DELETE | `/v1/mollie/customers/{id}/mandates/{mandate_id}` | | ⬜ |
| GET | `/v1/mollie/customers/{id}/subscriptions` | | ⬜ |
| POST | `/v1/mollie/customers/{id}/subscriptions` | | ⬜ |
| GET | `/v1/mollie/customers/{id}/subscriptions/{sub_id}` | | ⬜ |
| DELETE | `/v1/mollie/customers/{id}/subscriptions/{sub_id}` | | ⬜ |

## Mollie — Connect

Partner onboarding: organisations, profiles and permissions of a connected
Mollie organisation.

| | Endpoint | SDK | |
|---|---|---|---|
| GET | `/v1/mollie/connect/onboarding/me` | | ⬜ |
| GET | `/v1/mollie/connect/organizations/me` | | ⬜ |
| GET | `/v1/mollie/connect/organizations/{id}` | | ⬜ |
| GET | `/v1/mollie/connect/profiles` | | ⬜ |
| POST | `/v1/mollie/connect/profiles` | | ⬜ |
| GET | `/v1/mollie/connect/profiles/{id}` | | ⬜ |
| GET | `/v1/mollie/connect/permissions` | | ⬜ |
| GET | `/v1/mollie/connect/permissions/{id}` | | ⬜ |
| POST | `/v1/mollie/connect/client-links` | | ⬜ |

## Account subscriptions

| | Endpoint | Ability | SDK | |
|---|---|---|---|---|
| POST | `/v1/account-subscriptions` | `mollie:write` | | ⬜ |
| GET | `/v1/account-subscriptions` | `mollie:read` \| `mollie:write` | | ⬜ |
| GET | `/v1/account-subscriptions/{id}` | `mollie:read` \| `mollie:write` | | ⬜ |
| DELETE | `/v1/account-subscriptions/{id}` | `mollie:write` | | ⬜ |
| POST | `/v1/account-subscriptions/{id}/pause` | `mollie:write` | | ⬜ |
| POST | `/v1/account-subscriptions/{id}/resume` | `mollie:write` | | ⬜ |

## Billing

| | Endpoint | Ability | SDK | |
|---|---|---|---|---|
| GET | `/v1/billing/subscription` | `billing:read` \| `billing:write` | | ⬜ |

## Reaching what is not wrapped

`Hub::connector()` is public, so every unwrapped endpoint is reachable without
waiting for a release:

```php
use Saloon\Enums\Method;
use Saloon\Http\Request;

$response = Hub::connector()->send(new class('acme-42') extends Request {
    protected Method $method = Method::GET;

    public function __construct(private readonly string $accountId) {}

    public function resolveEndpoint(): string
    {
        return '/exact/gl-accounts';
    }

    protected function defaultHeaders(): array
    {
        return ['X-Account-Id' => $this->accountId];
    }
});
```

Mind that header. `X-Account-Id` does not live on the connector; resources set it
per request through `HasAccountIdHeader`. Leave it out and the Hub cannot resolve
an Account, and therefore no Connection — which affects everything under
`/accounting/*`, `/exact/*` and `/mollie/*`.

Error handling does carry over: `MapHubErrors` is registered as response
middleware on the connector (`HubConnector.php:28`), so a raw request throws the
same exception tree a wrapped one does.

## Suggested order

1. `POST /v1/connections` — completes the lifecycle for non-OAuth providers
2. `GET /v1/billing/subscription` — one endpoint, and the consumer app needs to
   know whether a subscription is active anyway
3. Mollie payments — the largest block, relevant once the app initiates payments
   rather than only booking them
4. Mollie Connect and account subscriptions — follow on from 3
5. Exact/Snelstart pass-through — deliberately last: anyone who needs these wants
   provider-specific behaviour by definition, and is better served by
   `connector()` than by a wrapper that implies provider neutrality
