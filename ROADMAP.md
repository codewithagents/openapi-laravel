# Roadmap

Status: **0.3.0 released on Packagist; 0.4.0 in progress on main (unreleased local stack, nothing
pushed).** The v1 models generator and v2 server scaffold are shipped. 0.3.0 added composition
keywords (allOf merge, additionalProperties maps), a full security-hardening pass, and edge-case
fixes mined from the openapi-zod-ts sibling history. 0.4.0 so far adds **oneOf/anyOf union types**
and a **cross-language end-to-end demo** (`e2e/`) that proves the contract-first loop over real
HTTP. The version target is deliberately 0.4.0, not 1.0.0: we stay in 0.x while the generated
output format is still evolving, and tag 1.0.0 (the API-stability promise) only when the feature
set settles.

## Done and committed toward 0.4.0 (unreleased, local on `main`)

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
  additionalProperties map, and a oneOf scalar union. Every one was proven to round-trip over real
  HTTP, including: writeOnly never returned, readOnly server-set and ignored on input, enum 422
  from the generated rules(), MapName mapping both directions, and the `string|int` union
  preserving its JSON type with no coercion.
- Next e2e milestones (in progress): a containerized SPA consuming the published
  openapi-zod-ts@1.0.1 generated client, a docker-compose stack, and a Playwright headless-Chrome
  suite driving the browser -> SPA -> generated client -> backend.

### Bug found by the e2e push (a real generator finding)

**An empty `additionalProperties` map serializes as `[]`, not `{}`.** PHP cannot distinguish an
empty associative array from an empty list, so `json_encode([])` emits an array. Non-empty maps and
null are correct; only the empty case is wrong. A strict client expecting `Record<string,string>`
would receive an array and reject it. Candidate 0.4.0 fix: emit a cast/transformer on generated map
properties that forces object encoding even when empty, or document it as a known limitation. This
is exactly the class of bug only a real cross-language round-trip exposes.

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
8. **Version 0.4.0 now, 1.0.0 when out of ideas.** Stay in 0.x while the output format evolves.

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

## Remaining work toward tagging 0.4.0

1. **additionalProperties empty-map encoding**: fix the `[]` vs `{}` serialization (cast/transformer
   to force object encoding) or document it. Surfaced by the e2e demo.
2. **Finish the e2e demo**: SPA container + docker-compose + Playwright headless-Chrome E2E, green.
3. **Quality tooling (under consideration)**: add cheap high-value free tools as CI/PR jobs and
   local scripts. Candidates, additive to the current PHPStan-max / Pint / type-coverage / mutation
   / composer-unused stack: `composer audit` (vulnerable deps), Deptrac (enforce the
   Parser/Naming/Emitter/Console layering), Psalm taint-analysis (security, given the untrusted-spec
   threat model), and optionally SonarCloud or Qodana (free for the public repo, PR decoration).
4. **DX on bad input**: a `--dry-run` summary and richer error messages.
5. **Published-artifact CI job**: install the published Packagist release and smoke-generate.
6. **CI maintenance**: bump `actions/checkout` off the deprecated Node 20.
7. **Tag 0.4.0** via release-please once the above settle.

Lower-severity lossy cases (document or improve, not blockers): tuple `prefixItems`
(`array<int, mixed>`), int64/bignum literal bounds, non-JSON responses (JsonResponse fallback),
`$ref`-valued map values (typed in docblock, raw arrays at runtime), mixed-object overflow keys.

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
the model and server gates), plus the allOf/additionalProperties/oneOf non-empty guards. Hostile-
input suite for the security surfaces. Regression fixtures for the sibling-history edge cases.
Pest native mutation (>=90%), 100% type coverage, PHPStan max, Pint. The e2e demo adds real-HTTP
round-trip proofs across the language boundary. Current totals: 580 package tests passing locally.

## Open questions (genuine design decisions)

- **Object-union hydration**: emit a discriminator-driven cast for `oneOf` of Data classes so object
  unions hydrate at runtime, not just type-check. Currently the documented residual.
- **Component request bodies / responses as typed params**: resolve `$ref` to
  `#/components/requestBodies/...` into a typed Data parameter instead of the `Request` fallback.
- **additionalProperties empty-map** object-encoding cast (the e2e finding).
- **Exact laravel-data v4 feature surface** for casts/transformers (dates, enums, nested + typed-map
  value hydration).

Resolved: allOf, additionalProperties, oneOf/anyOf, the security surfaces, and the two
sibling-history bugs are implemented. The cross-language contract loop is proven over real HTTP for
the scalar cases. Floors are PHP 8.2 + Laravel 11/12 + laravel-data v4.
