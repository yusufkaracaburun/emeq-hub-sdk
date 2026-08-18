---
paths:
  - 'src/**'
  - 'tests/**'
  - 'routes/**'
---

# Comments

## No explanatory comments in PHP source

Source carries no prose. No `//` reasoning, no `/* */` banners, no descriptive
docblock text. Say it in the name, the type, or a test — not in a comment.

Kept, because they are type information or tooling directives rather than prose:
`@param`, `@return`, `@var`, `@throws`, `@property`, `@method`, `@template`,
`@mixin`, array shapes, `@phpstan-*`, `@psalm-*`, and PHPUnit annotations.
`composer analyse` depends on these — a docblock holding only an array shape
stays.

Applied across the package in `7f7fbc3` (96 files, −1643 lines). Re-adding prose
regresses it.

## Route-action docblocks are an artefact, not prose

`src/Http/Controllers/IntegrationController::index()` and `connectSession()` are
the two route actions this package ships. `emeq/system` generates its OpenAPI
document (`api.json`) from its route handlers, **including these two**, and reads
the first docblock line as `summary` and the rest as `description`.

Stripping them in `7f7fbc3` therefore did not tidy the SDK — it blanked two
endpoints in a consumer's published API documentation, which only surfaced when
that consumer upgraded to v0.24.0 and its pre-commit hook regenerated the file.
Restored in v0.24.1.

The same reasoning covers the docblock on `connectSessionResponse()`: it records
why the response is narrowed to `string|null`, which is what keeps the generated
schema and the consumer's TypeScript off `any`.

Before removing prose from anything under `src/Http/Controllers/**` or
`routes/**`, check what a consumer's generator reads from it.

## config/hub.php is the other exception

That file is copied into consumer applications by `vendor:publish`, where its
banner comments are the configuration documentation a consumer reads. It keeps
its comments.

## This overrides the generated guideline

`CLAUDE.md` (and its `AGENTS.md` symlink) says *"Prefer PHPDoc blocks over inline
comments"* — generated output from `.ai/guidelines/*.blade.php`, not hand-edited.
Read it as: when something genuinely must be recorded in the file, a typed
docblock beats an inline comment. It is not licence to write prose docblocks.
