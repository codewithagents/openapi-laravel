# Roadmap

Status: **0.5.0 released on Packagist; 0.6.0 (drift check) prepared and CI-green on `main`.**
The v1 models generator and v2 server scaffold are shipped. 0.3.0 added composition keywords
(allOf merge, additionalProperties maps), a full security-hardening pass, and edge-case fixes mined
from the openapi-zod-ts sibling history. 0.4.0 shipped **oneOf/anyOf union types** and a
**cross-language end-to-end demo** (`e2e/`), with the full browser loop green. 0.5.0 was a
correctness-and-robustness pass: a large **silent-validation** sweep, **non-object component
aliasing** (no more empty Data classes), **parser hardening**, and broader CI tooling. 0.6.0 is the
enforcement pass: the **drift-check command**, the **conformance golden test**, and the array-of-union
DataCollectionOf fix (bug #24). The version target stays deliberately in 0.x, not 1.0.0: we stay in
0.x while the generated output format is still evolving, and tag 1.0.0 (the API-stability promise)
only when the feature set settles.

## Done in 0.6.0 (CI-green on main)

### Drift-check command (`php artisan openapi:check`)

The headline feature. Regenerates the full set of files the generator would write, in memory, and
compares them byte-for-byte against what is on disk, without writing anything. Use it in CI to fail
the build when committed generated code has drifted from the spec. Also available as the
framework-free `vendor/bin/openapi-laravel check`.

Exit codes: `0` = in sync, `1` = drift detected, `2` = config or spec error. The `--diff` flag
prints a bounded unified diff for each changed file. The command honors the same flags as
`openapi:generate` (`--spec`, `--output`, `--namespace`, `--controllers`, `--routes`). Only
generator-owned files are compared, so hand-written concrete controllers are never flagged as drift.

Internally, generate and check now share one code path via a `GenerationPlanner` that computes the
full file set. Both commands call it, so they can never diverge in which files they consider or in
the content they would produce.

### Conformance golden test

A single OpenAPI 3.1 spec exercises every generator construct family (exclusive bounds,
`multipleOf`, `uniqueItems`, float enums, multi-type unions, strict date-time, defaults,
non-object aliasing, union arrays). A golden test pins the per-construct output, runs a `php -l`
compile check on it, and verifies byte-for-byte determinism on every CI run. It caught bug #24
before the release.

### Bug #24: array-of-union emits a plain typed array, not DataCollectionOf

An array whose `items` are a `oneOf`/`anyOf` union previously emitted
`#[DataCollectionOf(A|B::class)]`. PHP operator precedence causes `php -l` to accept that
syntax, but at runtime it resolves to `DataCollectionOf(A | (B::class))`, which is wrong. Such
an array now emits a plain typed `array<int, A|B>` property with a docblock and no collection
attribute. Object-union hydration is still the documented residual (needs a discriminator or custom
cast); this fix stops the generator from producing silently broken collection attributes.

## Done in 0.5.0 (released)

### Silent-validation correctness pass

Adversarial review surfaced spec constraints the generator dropped or mishandled, each letting
invalid input pass silently. All closed, each with a unit test plus a behavioral validator
round-trip:
- **Exclusive bounds**: `exclusiveMinimum`/`exclusiveMaximum` emit `gt:`/`lt:`, covering both the 3.0
  boolean companion (`minimum` + `exclusiveMinimum: true`) and the 3.1 numeric keyword form.
  Inclusive bounds still emit `min:`/`max:`.
- **Number/float enums**: floats are included in the enum values, so a `number` enum emits `Rule::in`
  instead of accepting any number. A top-level float-enum component (which cannot be a PHP backed
  enum) emits a single-value Data class carrying the constraint, not an empty class.
- **Multi-type arrays**: `type: ["string","integer"]` emits a `string|int` union with presence-only
  rules, reusing the union machinery. `["x","null"]` stays a nullable scalar.
- **multipleOf**: enforced via a reusable `MultipleOfRule` (Laravel has no native rule), attached to
  numeric properties.
- **uniqueItems**: adds `distinct` to the array item rules.
- **Defaults**: a scalar `default` seeds the constructor parameter instead of `null`, and a property
  with a default is treated as `sometimes` (optional), not `required`, on input.
- **Strict date-time**: `format: date-time` emits a reusable `Rfc3339DateTimeRule` that accepts the
  Z/offset/fractional RFC3339 forms and rejects a bare date, while `format: date` keeps
  `date_format:Y-m-d`. The old shared `date` rule wrongly accepted a date-time on a date field and a
  bare date on a date-time field.
- **No `?mixed`**: a nullable mixed-fallback property now renders `mixed` (never the illegal
  `?mixed`, a hard PHP compile error since `mixed` already includes null).

All 128 corpus specs still generate clean, valid PHP with zero `?mixed`; output stays deterministic.

### Non-object component aliasing (empty-class removal)

A top-level component schema that is itself a scalar, an array, or a `oneOf`/`anyOf` union (no object
properties) used to be emitted as an empty `final class XxxData extends Data {}` and referenced as a
property type, so the value silently failed to hydrate. Non-object components are now treated as
**type aliases**, mirroring the existing pure-map handling: at every `$ref` use site they resolve to
the underlying type (scalar plus the rules the schema implies, array/`DataCollection`, or the native
union), with chained aliases resolved transitively and cycle-guarded. A `type: object` component with
no properties stays a legitimately empty object and still emits an empty Data class. The server
scaffold inherits the fix: alias components fall back to `Request`/`JsonResponse` rather than a
missing class. Corpus impact: about 1150 empty Data classes removed across the 128 specs, fixing
silent data loss; generation stays byte-identical (128/128) and the import-resolution gate stays
green.

### Parser hardening

- **Boolean `items`**: cebe cannot instantiate a Schema from a boolean, so valid OpenAPI 3.1
  `items: true` (and the closed-tuple `prefixItems` + `items: false`) threw. A `SchemaNormalizer`
  now rewrites boolean `items` in the raw decoded spec before cebe sees it (`true` becomes an empty
  any-schema, `false` is dropped). The parser decodes, normalizes, then instantiates.
- **OOM surfaced cleanly**: a true OOM inside symfony/yaml is a non-catchable `E_ERROR` fatal, so a
  `MemoryGuard` shutdown handler now inspects `error_get_last()` and prints an actionable message
  (raise `memory_limit` or reduce the spec) instead of a raw trace. The `--max-bytes` guard warns
  that raising the limit needs a proportionally larger `memory_limit`.

### Serialization and DX

- **Empty `additionalProperties` map** now serializes as `{}` not `[]`, via a reusable
  `MapObjectTransformer` attached to every map-typed property. This closes the e2e finding: PHP
  cannot distinguish an empty associative array from an empty list, so a strict client expecting
  `Record<string,string>` previously received `[]` and rejected it. Non-empty maps and `null` were
  always correct; only the empty case was wrong.
- **`--namespace` flag** on the `openapi:generate` artisan command, overriding the configured output
  namespace and reusing the same legal-PHP-namespace validation as the standalone binary (an invalid
  value fails before any file is written).

### Test and CI infrastructure

- **`php -l` compile gate**: the corpus generate gate validated output with `token_get_all`, which
  only tokenizes and accepts code that cannot compile (e.g. `?mixed`, `bool $x = 'str'`); that is how
  the `?mixed` bug slipped past. A real compile-level `php -l` pass now lints the full
  conformance-fixture output plus a deterministic 20-file slice of every corpus spec, with a short,
  auditable by-name exempt list for pre-existing src-side residuals.
- **OpenAPI 3.1 conformance fixture**: a synthetic spec exercising the full generator surface ships
  in 0.5.0. Its golden-output test is wired in 0.6.0 (see "Done in 0.6.0").
- **Dependency and quality tooling**: `composer audit`, Deptrac (architecture layering), and Qodana
  run in CI alongside the existing PHPStan-max / Pint / type-coverage / mutation / composer-unused
  stack.

## Done in 0.4.0 (released)

### oneOf / anyOf union types

A property or schema typed via `oneOf`/`anyOf` (handled identically) now emits a native PHP 8.2
union type instead of bare `mixed`, via `resolveUnion` in ModelGenerator:
- If every member resolves to a clean PHP type (scalar or generated Data class), emit a union in
  source order, deduped, with a `@var` variant docblock: `string|int`, `CatData|DogData`,
  `string|CatData`. Nullability renders as a trailing `|null` member (never the illegal `?A|B`),
  added when a member is the null type, a member is nullable, or the property is nullable.
- Deterministic fallback to `mixed` with presence-only rules for messy members (nested
  oneOf/anyOf, array, map, inline object, untyped schema, enum member, $ref to a non-Data class).
- Rules stay presence-only: no rule is invented to enforce "exactly one variant", which standard
  Laravel cannot do without a discriminator.
- Server scaffold: a success response that is a oneOf/anyOf of Data-class `$ref`s is typed as a
  Data-class union return (both imported); otherwise the JsonResponse fallback stands.
- Impact: 1088 properties across 15 specs moved from bare `mixed` to a native union (480 scalar,
  608 object/mixed). Top contributors stripe (505), openai (430), github (110). No spec regressed.
- Honest residual (documented): spatie/laravel-data does not auto-hydrate an object union
  (CatData|DogData) without a discriminator or custom cast. The union type hint plus docblock is a
  typing/IDE/PHPStan win, scalar unions hydrate fine, object-union runtime hydration still needs a
  discriminator. Proven end to end in the e2e demo for the scalar case.

### Cross-language end-to-end demo (`e2e/`, export-ignored)

A contract-first proof living inside the repo (never shipped in the dist):
- One shared petstore-plus spec (`e2e/spec/petstore.yaml`) is the source of truth. openapi-laravel
  (dogfooded via a composer path-repo symlink, so the demo tests the working tree) generates the
  Data classes, abstract controllers, and routes for a real Laravel 12 backend. Hand-written
  concrete controllers back the abstracts with a file-backed JSON store (persists across the
  separate worker processes of php artisan serve and Docker).
- The petstore-plus spec deliberately stresses the cross-language serialization seams: a snake_case
  wire name forcing `#[MapName]`, a writeOnly field, a readOnly date-time, a nullable number, an
  additionalProperties map, and a oneOf scalar union. Every one is proven to round-trip over real
  HTTP, including: writeOnly never returned, readOnly server-set and ignored on input, enum 422
  from the generated rules(), MapName mapping both directions, and the `string|int` union
  preserving its JSON type with no coercion.
- **The full cross-language loop is complete and green.** A containerized SPA consumes the generated
  openapi-zod-ts client, a docker-compose stack (`e2e/docker-compose.yml`) boots the generated
  backend on `:8088` and the SPA on `:8080`, and a Playwright headless-Chrome suite
  (`e2e/e2e-tests`, run with `./run.sh`) drives the browser -> SPA -> generated client -> backend
  over real HTTP. Verified in the browser: seeded pets load, a valid create -> 201, an invalid
  create -> 422 from the generated rules(), readOnly/writeOnly variant behavior, the oneOf scalar
  union round-trip (string and integer), nullable null, the attributes map, status-filter tabs, and
  a delete -> 204. The Docker images boot the committed generated code as-is, so what ships in the
  repo is exactly what the suite proves.

### Findings from the e2e push (real cross-language findings)

**An empty `additionalProperties` map serializes as `[]`, not `{}` (openapi-laravel).** PHP cannot
distinguish an empty associative array from an empty list, so `json_encode([])` emits an array.
Non-empty maps and null are correct; only the empty case is wrong. A strict client expecting
`Record<string,string>` would receive an array and reject it. This is exactly the class of bug only a
real cross-language round-trip exposes. **Fixed in 0.5.0** via a `MapObjectTransformer`.

**The openapi-zod-ts client omits the `Accept: application/json` header (openapi-zod-ts, cross-repo
follow-up).** The generated TypeScript client sends `Content-Type` but not `Accept`. A browser fetch
with no explicit `Accept` defaults to `text/html,...`, so Laravel's `wantsJson()` returns false and
the error path returns an HTML redirect instead of a 422 JSON response. That broke every
state-changing browser request until the demo backend added a `ForceJsonAccept` middleware. This is
not a bug in the generated Laravel code; it is a gap in the openapi-zod-ts client generator, filed
upstream as openapi-zod-ts #289 (https://github.com/codewithagents/openapi-zod-ts/issues/289).

## Done in 0.3.0 (released)

- **allOf merge** plus hardening (nullable union across members, read/write split detection across
  members, corpus non-empty guard). 863 previously-empty classes across 25 specs populated.
- **additionalProperties** as typed maps with per-value rules; 128 pure-map components across 20
  specs fixed (empty-class bug killed).
- **Security hardening** (spec as untrusted input): docblock injection neutralized, namespace/suffix
  option validation, regexRule never silently drops a pattern, non-OpenAPI document rejected with a
  clear error, YAML input-size guard, O(1) allOf cycle guard, and a hostile-input regression suite.
- **Edge-case fixes mined from openapi-zod-ts history**: the `$ref`-requestBody missing Request
  import (72 controllers across 14 specs), the `this`-property fatal, and `const` as a single-value
  enum. Plus an import-resolution corpus gate that makes the unresolvable-class bug class
  impossible to regress (verified adversarially).
- **Repo governance**: branch protection on `main`, `FUNDING.yml`.

## Vision

`codewithagents/openapi-laravel`: takes an OpenAPI spec (the source of truth) and generates Laravel
models and a server scaffold from it. The sibling of `openapi-zod-ts` (same author, same philosophy:
owned output, readable generated code, zero magic) for the Laravel ecosystem.

Quality bar: valid, PHPStan-max-clean output for all 128 real-world specs in
`tests/Fixtures/specs/`, with the import-resolution gate, before anything ships.

## Market context (researched 2026-06-10)

- Laravel: 535M Packagist installs, 64% PHP framework share. Code-first spec generation is crowded
  (l5-swagger, dedoc/scramble), do NOT compete there. Spec-first (spec -> code) is nearly empty:
  ensi-platform/laravel-openapi-server-generator (custom OAS extensions), rogervila/openapi-laravel
  (obscure). Optic archived Jan 2026, no OSS replacement. This is the open lane.

## Decisions made (do not re-litigate without reason)

1. **True PHP package**, not an emitter inside the openapi-zod-ts monorepo.
2. **Parser: do not write one.** Wrap `devizzent/cebe-php-openapi` behind our own `Parser`
   namespace. Parse with `validate: false`, lazy `$ref` resolution, depth bound, plus our own
   lightweight structural check.
3. **Output style: spatie/laravel-data classes** with explicit, spec-derived `rules()` methods.
   Native backed enums for spec enums.
4. **Spec fixtures duplicated** from openapi-zod-ts (public artifacts).
5. **Targets**: PHP 8.2+, Laravel 11/12, laravel-data v4.
6. Package name: `codewithagents/openapi-laravel`.
7. **The sibling history is a test oracle.** openapi-zod-ts's fixed bugs are a checklist of
   real-world edge cases to verify against this generator, independent of the corpus.
8. **Stay in 0.x now, 1.0.0 when out of ideas.** Tag 1.0.0 only when the output format settles.

## Versions

### v1: Models [shipped]
laravel-data classes, spec-derived rules(), backed enums, readOnly/writeOnly split, nested objects,
collections, allOf merge, additionalProperties maps, oneOf/anyOf unions, the naming layer (incl. the
`this` special case), determinism. Artisan command plus framework-free `vendor/bin`.

### v2: Server scaffold [shipped in 0.2.0]
Generated routes + one abstract controller per tag, typed by the Data classes. Unimplemented = PHP
fatal. Path-level drift structurally impossible. Remaining limitation: a component `$ref` request
body falls back to `Illuminate\Http\Request` (now correctly imported) instead of a typed Data param.

### v3 (maybe): client generation (`Http::` based). Decide later.
The forward expansion: generate a typed PHP client for *consuming* a third-party or internal API
(e.g. a typed PayPal/Stripe/microservice client) from its spec, built on the `Http::` facade. Decide
later, not a near-term commitment.

## Next work (toward 1.0.0)

With 0.6.0 the enforcement layer is in place: drift is now a CI gate, not just a property of
generated code. The remaining open items before 1.0.0 are runtime correctness and format stability.

1. **Object-union runtime hydration**: emit a discriminator-driven cast so a `oneOf` of Data classes
   hydrates at runtime, not just type-checks. Currently the documented residual.
2. **Differential conformance harness** (issue #23): derive a conforming and a violating payload per
   emitted constraint and assert Validator agreement across the corpus. Today the executed-validation
   guarantee covers the behavioral suite and the e2e demo, not every constraint of every corpus spec.
3. **Small follow-ups**: richer error messages on bad input, `$ref`-valued request bodies as typed
   Data params instead of the `Request` fallback.
4. **Cross-repo follow-up**: the openapi-zod-ts `Accept: application/json` gap (issue #289), surfaced
   by the e2e demo. Fix lives in the sibling repo, not here.

Lower-severity lossy cases (document or improve, not blockers): tuple `prefixItems`
(`array<int, mixed>`), int64/bignum literal bounds, non-JSON responses (JsonResponse fallback),
`$ref`-valued map values (typed in docblock, not auto-hydrated at runtime), mixed-object overflow
keys.

## Security posture

Spec is untrusted input, generated PHP is loaded and executed by the host. Defenses: docblock
injection neutralized, identifier whitelist (path traversal + identifier injection + the `this`
fatal), escaped literals, validated namespace options, structural rejection of non-OpenAPI docs,
input-size guard plus documented OS-limit guidance for the YAML-alias-bomb residual,
operator-controlled output paths, and a hostile-input regression suite. Residual: a caller who fully
controls the CLI/config can still choose hostile output paths; YAML alias expansion happens inside
the vendored parser, so size guard plus OS limits are the mitigation.

## Repo and release governance

- **Now (pre-1.0.0):** direct commits to `main`, push on maintainer approval. Branch protection
  blocks force-push/deletion, restricts push to the maintainer, requires CI status checks, but
  `enforce_admins` is off and PR reviews are not required so the direct-push flow works.
- **At 1.0.0 (the cutover):** flip `enforce_admins: true`, require 1 PR review, set required checks
  `strict: true`, and move to a PR + review workflow.

## Test strategy

Pest unit tests per feature (minimal in-memory spec builder) plus snapshots. Corpus: all 128
fixtures parse and generate valid PHP, passing the import-resolution gate (256 corpus cases across
the model and server gates), the `php -l` compile gate (catches compile errors `token_get_all`
misses), plus the allOf/additionalProperties/oneOf non-empty guards. A synthetic OpenAPI 3.1
conformance fixture covers the full generator surface, with a golden test (wired in 0.6.0) that
pins the per-construct output, runs `php -l`, and verifies byte-for-byte determinism. Hostile-input
suite for the security surfaces. Behavioral validator round-trips pin each 0.5.0 constraint
(exclusive bounds, multipleOf, uniqueItems, float enums, strict date-time, defaults). Regression
fixtures for the sibling-history edge cases. Pest native mutation (>=90%), 100% type coverage,
PHPStan max, Pint. The e2e demo adds real-HTTP round-trip proofs across the language boundary, plus
a Playwright headless-Chrome suite that drives the full browser -> SPA -> generated client ->
backend loop against a docker-compose stack. Current totals: 840 package tests passing.

## Open questions (genuine design decisions)

- **Object-union hydration**: emit a discriminator-driven cast for `oneOf` of Data classes so object
  unions hydrate at runtime, not just type-check. Currently the documented residual.
- **Component request bodies / responses as typed params**: resolve `$ref` to
  `#/components/requestBodies/...` into a typed Data parameter instead of the `Request` fallback.
- **Map-of-`$ref` value hydration**: a `$ref`-valued `additionalProperties` map is typed in the
  docblock but not auto-hydrated into Data objects at runtime.
- **Exact laravel-data v4 feature surface** for casts/transformers (dates, enums, nested + typed-map
  value hydration).

Resolved: allOf, additionalProperties (including empty-map `{}` encoding), oneOf/anyOf, non-object
component aliasing, the silent-validation gaps, the parser boolean-items/OOM cases, the security
surfaces, the two sibling-history bugs, the array-of-union DataCollectionOf bug (#24), and the
drift-check enforcement layer are all implemented. The cross-language contract loop is proven end to
end over real HTTP, including the browser-driven Playwright suite; object-union runtime hydration is
the remaining open case. Floors are PHP 8.2 + Laravel 11/12 + laravel-data v4.
