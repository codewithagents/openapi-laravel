# openapi-laravel

> **Style rule:** Never use em dashes in any content, copy, or code comments. Use commas, colons, or full stops instead.

OpenAPI -> Laravel model generator. Sibling of `openapi-zod-ts` (same author, same philosophy),
published as `codewithagents/openapi-laravel` on Packagist. @codewithagents OSS, casual/OSS mode:
move deliberately, but this is our project, no client constraints.

Infrastructure (exists already): remote `https://github.com/codewithagents/openapi-laravel.git`
(public, GitHub Pages enabled), docs site `https://openapi-laravel.codewithagents.de`
(point Pages there when docs land, see roadmap phase 6).

**Read `ROADMAP.md` first in every session.** It holds the decisions already made, the version
plan, and the open questions. Do not re-litigate decided items without new information.

## Current state

**Current release: `0.4.0`** on Packagist (`0.2.0` shipped the server scaffold, `0.3.0` shipped
composition + the security pass, `0.4.0` ships `oneOf`/`anyOf` union types and the e2e demo).
ROADMAP.md holds the decisions and open questions; if it still phrases 0.4.0 as in-progress it just
predates the release tag, the released feature set is the one described here.

**Models (v1, shipped):** laravel-data classes, spec-derived `rules()`, backed enums,
readOnly/writeOnly split, nested objects, collections. Composition keywords are handled: `allOf` is
merged into one flat class, `additionalProperties` becomes typed maps (`array<string, X>`) with
per-value rules, and `oneOf`/`anyOf` emit native PHP union types (`string|int`, `CatData|DogData`)
plus a variant docblock, with a deterministic `mixed` fallback for messy members. Parser (lazy refs,
128 specs parse), naming layer (incl. the `this` special case), the `openapi:generate` artisan
command, and a framework-free `vendor/bin/openapi-laravel`.

**Server scaffold (v2, shipped in 0.2.0):** an abstract controller per tag plus a routes file, typed
by the Data classes. Opt in with `--controllers`/`--routes`. A `oneOf`/`anyOf`-of-Data-class response
is typed as a union return.

**Security:** the spec is treated as untrusted input. Docblock-injection neutralization, identifier
whitelisting, option validation, structural rejection of non-OpenAPI docs, an input-size guard, and a
hostile-input test suite.

**Quality:** PHPStan max, Pint, 100% type coverage, Pest native mutation, the import-resolution
corpus gate (catches unresolvable class references), composer-unused/require-checker, `composer
audit`, Deptrac architecture enforcement, and a Qodana workflow. 580 tests green; all 128 corpus
specs generate valid PHP.

**Honest residuals (documented, not hidden):** object-union (`oneOf` of Data classes) does not
auto-hydrate in laravel-data without a discriminator; an empty `additionalProperties` map serializes
as `[]` not `{}` (known issue, surfaced by the e2e demo); a component `$ref` request body falls back
to `Illuminate\Http\Request` (now correctly imported) instead of a typed Data param; tuple
`prefixItems`, int64 bounds, and non-JSON responses degrade gracefully.

**Cross-language e2e demo (`e2e/`, owned by another agent, do not edit):** one petstore-plus spec
drives a generated Laravel backend and (in progress) an openapi-zod-ts TypeScript client + SPA, with
a headless-Chrome E2E over real HTTP. The backend and contract round trip are proven; the SPA, Docker
stack, and browser suite are being finalized.

In flight after `0.4.0`: the empty-map encoding fix (or keep it documented), and finishing the
cross-language e2e demo (SPA + docker-compose + Playwright). See ROADMAP.md.

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
- Pest for tests (incl. native mutation), PHPStan at max level, Laravel Pint for style.
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
