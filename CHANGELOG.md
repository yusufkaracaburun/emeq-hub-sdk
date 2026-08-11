# Changelog

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
