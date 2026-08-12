## This repository is a package, not an application

`emeq/hub-sdk` is a Laravel consumer SDK for the emeq Hub `/v1` API. It is installed *into* other Laravel applications; it is not an application itself. Treat every generic "your application" guideline above through that lens.

Consequences that override the generic guidance:

- There is no `app/` directory, no HTTP kernel, no database and no application log. Source lives in `src/` under the `Emeq\HubSdk\` namespace, published assets in `config/` and `database/migrations/`, routes in `routes/hub.php`.
- The `artisan` file in the repository root is a development-only shim that boots a bare application rooted at the package so Boost and other tooling resolve `base_path()` correctly. It is excluded from the distributed archive and must never be referenced by package code.
- Tests run on Orchestra Testbench + Pest: `composer test` (or `vendor/bin/pest`). Never call `php artisan test`; there is no application to test.
- Static analysis is Larastan: `composer analyse`. Formatting is Pint: `composer format`.
- The service provider is `Emeq\HubSdk\HubServiceProvider`, built on `spatie/laravel-package-tools`. Register new config, migrations, routes and commands through `configurePackage()`, not by hand.
- Only `config/hub.php` is published to consumers. `config/boost.php` exists purely for local tooling.
- Public API changes are breaking changes for consumers. Keep `CHANGELOG.md` and `README.md` in step, and follow the existing semver discipline of this repository.
- The package must stay provider-agnostic: new Hub providers should surface without SDK changes. Do not hard-code provider names in the SDK's request/response paths.

### Agent files are deduplicated with symlinks

Every agent-facing artefact has exactly one physical copy. Do not "fix" a symlink by replacing it with a real file, and do not edit generated output by hand:

- `AGENTS.md` → symlink to `CLAUDE.md`. Content is generated; edit `.ai/guidelines/*.blade.php` and re-run `php artisan boost:install --guidelines`.
- `.cursor/mcp.json` → symlink to `.mcp.json`.
- `.claude/skills/<name>` and `.cursor/skills/<name>` → symlinks to `.ai/skills/<name>`, the single home for skills.

Boost writes through symlinks (`File::put`, `fopen(..., 'c+')`), so `boost:install` and `boost:update` keep this layout intact.
