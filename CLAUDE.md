# openapi-laravel

> **Style rule:** Never use em dashes in any content, copy, or code comments. Use commas, colons, or full stops instead.

OpenAPI -> Laravel model generator. Sibling of `openapi-zod-ts` (same author, same philosophy),
published as `codewithagents/openapi-laravel` on Packagist. @codewithagents OSS, casual/OSS mode:
move deliberately, but this is our project, no client constraints.

**Read `ROADMAP.md` first in every session.** It holds the decisions already made, the version
plan, and the open questions. Do not re-litigate decided items without new information.

## Current state

Scaffolding only. No working code. Next step: phase 1 of the v1 plan in ROADMAP.md
(composer skeleton that resolves, Pest + PHPStan max wired, CI matrix).

## Layout

```
src/Parser/    wraps devizzent/cebe-php-openapi, normalization, depth bounds
src/Naming/    spec names -> PHP identifiers (StudlyCaps, collisions, reserved words)
src/Emitter/   Data classes, enums, rules() emission
src/Console/   artisan command + standalone bin entry
tests/Fixtures/specs/   128 real-world OpenAPI specs (copied from openapi-zod-ts examples/specs)
```

## Conventions

- PHP 8.2+, strict_types everywhere, final classes by default.
- Pest for tests, PHPStan at max level, php-cs-fixer or pint for style (decide phase 1).
- Conventional commits with scopes (mirrors openapi-zod-ts release flow).
- Generated output must be deterministic: same spec in, byte-identical files out.
- Quality gate: a change is done when all 128 corpus specs generate cleanly and the generated
  output passes `php -l` + PHPStan max.
- README style mirrors openapi-zod-ts: one-liner, philosophy, honest comparison table, pipeline
  diagram. Reference: ../openapi-zod-ts/packages/openapi-zod-ts/README.md

## Sibling repo

`../openapi-zod-ts` is the design reference: parser/emitter separation, naming utilities
(`src/utils/naming.ts`), nullable normalization, writable-variants logic, snapshot + corpus
test strategy. Port ideas, not code.
