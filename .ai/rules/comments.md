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

## config/hub.php is the exception

That file is copied into consumer applications by `vendor:publish`, where its
banner comments are the configuration documentation a consumer reads. It keeps
its comments.

## This overrides the generated guideline

`CLAUDE.md` (and its `AGENTS.md` symlink) says *"Prefer PHPDoc blocks over inline
comments"* — generated output from `.ai/guidelines/*.blade.php`, not hand-edited.
Read it as: when something genuinely must be recorded in the file, a typed
docblock beats an inline comment. It is not licence to write prose docblocks.
