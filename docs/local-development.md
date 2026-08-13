# Local development

```bash
composer install
composer test        # Pest on Orchestra Testbench
composer analyse     # Larastan
composer format      # Pint
```

See [`CONTEXT.md`](../CONTEXT.md) for the domain language and structural rules,
and [`adr/`](adr/) for the decisions behind them.

## Laravel Boost

This repository ships [Laravel Boost](https://laravel.com/docs/boost) for AI agents. Because a package has no application, Boost is booted through the development-only `artisan` shim in the repository root — it creates a bare app rooted at the package so `base_path()` resolves here instead of inside `vendor/`. Everything Boost writes (`artisan`, `boost.json`, `config/boost.php`, `CLAUDE.md`, `.mcp.json`, `.ai/`, agent directories) is `export-ignore`d and never reaches consumers.

```bash
php artisan boost:install   # (re)wire guidelines, skills and MCP config
php artisan boost:update    # refresh guidelines after a dependency bump
```

MCP tools that need a running application (database, browser logs, application log, URL generation) are disabled in `config/boost.php`; `application-info`, `search-docs` and `record-rule` remain. Project guidelines specific to this package live in `.ai/guidelines/package.blade.php` — edit there, then re-run `boost:install --guidelines`, never edit the generated block in `CLAUDE.md` by hand.

## Agent artefacts are symlinked

Every agent artefact exists exactly once; the per-agent paths are symlinks:

| Physical file | Symlinks pointing at it |
| --- | --- |
| `CLAUDE.md` (generated) | `AGENTS.md` |
| `.mcp.json` | `.cursor/mcp.json` |
| `.ai/skills/<name>` | `.claude/skills/<name>`, `.cursor/skills/<name>` |

Boost writes through symlinks, so `boost:install` and `boost:update` keep this layout intact — verified against a full `--guidelines --mcp --skills` run. The trade-off is on skills only: anything under `.ai/skills` counts as a user skill and shadows the vendor copy, so Boost stops refreshing it. To pull in upstream changes for a Boost-shipped skill:

```bash
rm -rf .ai/skills/<name> .claude/skills/<name> .cursor/skills/<name>
php artisan boost:install --skills
```
