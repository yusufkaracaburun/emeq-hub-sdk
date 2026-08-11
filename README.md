# emeq Hub SDK

Laravel 13 consumer client for the emeq Hub `/v1` API (Saloon v4).

**Provider-agnostic:** new Hub partners (Moneybird, …) appear via
`Hub::integrations()->list()` and `Hub::oauth()->init($provider, …)` without an
SDK release. Partner wire stays in the Hub + `emeq/*-api` packages — this SDK
does **not** expose per-partner pass-through.

## Install

```bash
composer config repositories.emeq-hub-sdk vcs https://github.com/yusufkaracaburun/emeq-hub-sdk.git
composer require emeq/hub-sdk:^0.1
```

```env
EMEQ_HUB_BASE=https://hub.emeq.nl
EMEQ_HUB_PAT=your-sanctum-pat
```

Publish config (optional):

```bash
php artisan vendor:publish --tag=hub-config
```

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
}

// AppServiceProvider
$this->app->bind(ResolvesAccountId::class, HubAccountIdResolver::class);
```

## Usage

```php
use Emeq\HubSdk\Facades\Hub;

$providers = Hub::integrations()->list(); // data-driven
$init = Hub::oauth()->init($providers[0]['key'], returnUrl: $url);

Hub::accounts()->create('tenant-1', 'Acme B.V.');
Hub::accounting()->createDocument($payload, idempotencyKey: $key);
Hub::connections()->delete($connectionId);
```

## AI prompt — SDK in je Laravel-app

Plak onderstaande prompt in je coding-agent (Cursor, Claude Code, …) om
`emeq/hub-sdk` te installeren, te configureren en aan te sluiten op jouw
tenant-model. Vul de `{…}`-placeholders in vóór je plakt.

De prompt dekt **install + config + account-binding + dunne backend-routes**.
UI/kaarten/privacy-checkbox horen bij je app; die kun je daarna laten bouwen
met de agent-prompts in de Hub
[consumer-integration-guide](https://github.com/yusufkaracaburun/emeq-hub/blob/master/docs/consumer-integration-guide.md).

```text
Installeer en configureer emeq/hub-sdk in mijn Laravel-app zodat we de emeq Hub
(/v1) server-side kunnen aanroepen. Lees eerst de package-README:
https://github.com/yusufkaracaburun/emeq-hub-sdk

CONTEXT
- App: {Laravel 13 / pad naar repo}
- Hub base-URL: {https://hub.emeq.nl}
- PAT komt uit env (nog leeg of al bekend): EMEQ_HUB_PAT
- Mijn tenants worden onderscheiden door: {subdomein / instance.id /
  company_id / … — beschrijf hoe je de huidige tenant resolvet}
- Hub account `external_id` = {stabiele interne tenant-id, bv. instance.id —
  geen e-mail of domein}

DOE DIT (in deze volgorde)
1. Composer VCS-repo toevoegen en require:
   composer config repositories.emeq-hub-sdk vcs https://github.com/yusufkaracaburun/emeq-hub-sdk.git
   composer require emeq/hub-sdk:^0.1
2. Zet in `.env` / `.env.example`:
   EMEQ_HUB_BASE={https://hub.emeq.nl}
   EMEQ_HUB_PAT=
   (optioneel) EMEQ_HUB_TIMEOUT=30
3. Implementeer `Emeq\HubSdk\Contracts\ResolvesAccountId` in mijn app
   (bijv. `App\Integrations\Hub\HubAccountIdResolver`) die server-side de
   huidige tenant naar Hub `external_id` mapt volgens CONTEXT hierboven.
4. Bind die class in `AppServiceProvider::register()` aan
   `ResolvesAccountId::class`.
5. Bouw dunne, bestaande-auth-beschermde routes/controllers die ALLEEN via
   `Emeq\HubSdk\Facades\Hub` praten — geen eigen Http::withToken / Guzzle /
   Saloon-connector naar de Hub:
   - GET  …/integrations          → Hub::integrations()->list()
   - POST …/integrations/{provider}/connect → Hub::accounts()->create(...)
     (409/bestaat-al negeren) + Hub::oauth()->init($provider, returnUrl: …)
     en redirect naar redirect_url. Bouw return_url SERVER-SIDE uit de
     request-host; nooit uit de client-body. $provider is de Hub-key
     (string) — geen enum/allowlist in mijn code.
   - DELETE …/integrations/{connection} → Hub::connections()->delete($id)
     na check dat de connection bij de huidige tenant hoort.
6. Geen tokens, connection-state of provider-credentials in mijn DB. Status
   komt live uit Hub::integrations()->list().
7. Data-driven: hardcoded providerlijst of `if ($provider === 'exact')` is
   verboden. Nieuwe Hub-partners moeten vanzelf werken.
8. Fouten: laat HubException-subclasses door of map ze naar nette HTTP-
   responses; log `requestId` als die gezet is.

NIET DOEN
- Browser/directe calls naar de Hub (PAT blijft server-side)
- emeq/exact-api of andere partner-SDK’s in deze consumer-app installeren
- Per-partner pass-through wrappers bouwen
- X-Account-Id / account_external_id uit request van de client overnemen

KLAAR ALS
- composer show emeq/hub-sdk werkt
- ResolvesAccountId is gebonden
- Minstens één feature-test of smoke: list integrations (MockClient of
  Http fake) bewijst dat account-id server-side komt en niet uit een
  gespoofde request-header
```

## Growth model

| Change | Where |
|---|---|
| New partner | Hub only — SDK unchanged |
| New canonical `/v1` endpoint | This SDK (+ tag) |
| Partner HTTP/auth/DTOs | `emeq/<partner>-api` (Hub-internal) |

## Requirements

- PHP 8.3+
- Laravel 13
