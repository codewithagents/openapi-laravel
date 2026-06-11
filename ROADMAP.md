# Roadmap

Status: **0.6.0 released on Packagist; 0.7.0 (correctness and robustness hardening) prepared.**
The v1 models generator and v2 server scaffold are shipped. 0.3.0 added composition keywords
(allOf merge, additionalProperties maps), a full security-hardening pass, and edge-case fixes mined
from the openapi-zod-ts sibling history. 0.4.0 shipped **oneOf/anyOf union types** and a
**cross-language end-to-end demo** (`e2e/`), with the full browser loop green. 0.5.0 was a
correctness-and-robustness pass: a large **silent-validation** sweep, **non-object component
aliasing** (no more empty Data classes), **parser hardening**, and broader CI tooling. 0.6.0 is the
enforcement pass: the **drift-check command**, the **conformance golden test**, and the array-of-union
DataCollectionOf fix (bug #24). 0.7.0 is a second correctness-and-robustness wave: the
**differential validation oracle**, nested-array depth rules, string-typed-scalar coercion, a
per-property required warning, hostname enforcement, the object-union false-reject fix, and a
large real-world corpus sweep. The version target stays deliberately in 0.x, not 1.0.0: we stay in
0.x while the generated output format is still evolving, and tag 1.0.0 (the API-stability promise)
only when the feature set settles.

## Done since 0.7.0 (toward 1.0.0)

### Discriminator-aware object unions (issue #38)

The 1.0.0 object-union cornerstone has landed. A named `oneOf`/`anyOf` component that carries a
`discriminator` and whose members are all `$ref`s to object schemas now generates a real
discriminated union instead of the presence-only `mixed` fallback: an **abstract morphable base**
(spatie `PropertyMorphableData` plus a `morph()` that maps each discriminator value to a variant
class) and one **variant class** per member, each extending the base, forwarding the discriminator,
and declaring its own properties. A property typed `$ref` to the base resolves to the abstract base,
so spatie selects the concrete variant from the discriminator at runtime. The result both validates
per variant (a wrong-variant, unmapped, or missing-discriminator payload is rejected by the real
Laravel validator) and hydrates polymorphically (a `cat` payload becomes `CatData`), with no custom
rule or cast. Arrays of a discriminated union morph and validate each element. The mapping is
optional: with no `mapping`, each member's schema name is its implicit discriminator value. A
MapName discriminator works (the `morph()` reads the PHP property name, which is how spatie keys the
morph payload even under `#[MapName]`). The discriminator's own rules are emitted as validation
attributes, not a `rules()` method: a `rules()` method on the base would set
`hasDynamicValidationRules`, whose overwritten-rules pass replaces the inferred rules for the
discriminator key and silently drops spatie's morph guard, so an unmapped value would wrongly pass.

Scope and fallbacks (each degrades to the existing presence-only behavior and emits a build warning):
a non-object member, a variant shared by two discriminated bases (PHP single inheritance keeps the
first base), or a base left with no claimable variant. Still presence-only and tracked for a
follow-up: an **inline** discriminated union written directly under a property (not a named
component), and the **allOf-inheritance** form (a base object with properties plus a discriminator,
variants composing it with `allOf`). The full 130-spec corpus regenerates byte-identically with this
change, every generated file passes `php -l` and import resolution, and the differential oracle gains
a fully-enforced discriminated case with no known-gap entry. The undiscriminated object-union case
(#31) stays a tracked, documented limitation.

## Done in 0.7.0 (correctness and robustness hardening)

### Differential validation oracle (issue #23)

A data-driven, mutation-based internal quality harness. It generates one Laravel Data class per
emitted constraint family (string length, numeric bounds, format rules, enum, etc.) and runs a
conforming payload and a violating payload through the real Laravel Validator for each. A
constraint that is silently dropped fails the suite immediately. A known-gap ratchet documents every
acknowledged gap, preventing silent accumulation of new gaps. The oracle found and drove several of
the fixes below before the release. This is the correctness guarantee that was previously listed as
a roadmap item; it is now a permanent part of the quality baseline.

### Nested-array item rules at every depth (issue #28)

An array of arrays now propagates its inner item rules at every depth. Previously, the per-item
rules for the inner array were silently dropped, so invalid inner values passed the Validator
without error. Found by the oracle.

### String-typed scalar coercion (issues #32, #33)

Some Swagger generators emit numeric and length constraints, and the `nullable` flag, as JSON
strings (e.g. `"minimum":"8"`, `"nullable":"true"`). These were previously silently ignored,
dropping the constraint. Strictly-numeric strings and the literals `"true"` / `"false"` are now
coerced to the proper types before constraint emission. Anything ambiguous is left untouched. Found
by the oracle.

### Per-property required diagnostic (issue #34)

A non-standard per-property `required: true` key (a boolean set inside an individual property
schema, which some generators emit) is an OpenAPI anti-pattern: OpenAPI 3.x only honours the
schema-level `required: [...]` array. The generator already correctly ignored the key and generated
the property as optional. It now also emits a named stderr warning, giving the property name and
parent schema, so the silent information loss is surfaced. Both the artisan command and the
framework-free binary surface this warning channel.

### format: hostname enforcement (issue #29)

`format: hostname` was previously a no-op (only the `string` rule was emitted, so any string was
accepted). It now enforces RFC 1123 hostname syntax via a dedicated rule, and the duplicate
redundant `string` rule was removed. `idn-hostname` remains lenient (a non-empty, no-whitespace
check) to avoid wrongly rejecting valid unicode domain labels.

### Undiscriminated object-union interim fix (issue #31)

An undiscriminated `oneOf` of object refs is now typed `mixed` with presence-only validation, so
every valid variant is accepted and no valid non-first variant is false-rejected. The variant union
is preserved in the `@var` docblock. Full discriminator-aware variant validation and hydration is
tracked as 1.0.0 work (issue #38). See also the limitations documentation updated by the prior
release.

### Real-world corpus robustness sweep

Nine large public API specs (Stripe, GitHub, Box, Adyen, Asana, Sentry, Twilio) were run through
the generator as a robustness check. All 13,378 generated files compile-clean with zero generator
bugs found. Two construct-diverse specs (Sentry, Twilio v2010) were added to the permanent test
corpus, bringing it to 130 specs.

## Done in 0.6.0 (released)

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

With 0.7.0 the enforcement and correctness layers are in place: drift is a CI gate, the differential
oracle guards every emitted constraint, and the large real-world corpus sweep has found no generator
bugs. The remaining open items before 1.0.0 are runtime correctness and format stability.

1. **Discriminator-aware object-union validation and hydration** (issue #38): **landed** for the
   named-component `oneOf`/`anyOf` + `discriminator` form (see "Done since 0.7.0" above). Remaining
   follow-up: the **inline** discriminated union (discriminator written directly under a property,
   not a named component) and the **allOf-inheritance** form (base object with properties plus a
   discriminator, variants composing it with `allOf`). Both still degrade to presence-only with a
   warning. Undiscriminated object unions stay presence-only by design (#31).
2. **`additionalProperties: false` enforcement** (issue #30): a schema with
   `additionalProperties: false` currently emits no rule preventing extra keys. The spec intent is
   strict object shape; enforcement would reject payloads carrying undeclared properties. Tracked as a
   documented limitation until the enforcement strategy is decided.
3. **Small follow-ups**: richer error messages on bad input, `$ref`-valued request bodies as typed
   Data params instead of the `Request` fallback.
4. **Cross-repo follow-up**: the openapi-zod-ts `Accept: application/json` gap (issue #289), surfaced
   by the e2e demo. Fix lives in the sibling repo, not here.

### 1.0.0 readiness docs (issues #40, #41, #42)

Three documentation pages prepared for the 1.0.0 cutover. They live under
`docs/src/content/docs/guides/` and are wired into the docs sidebar:

- **Supported OpenAPI versions** (`guides/openapi-versions.mdx`, issue #42, **finalized**): the
  explicit version matrix. 3.0.x and 3.1.0 supported, Swagger 2.0 and non-3.x rejected; how the 3.0
  and 3.1 spellings (nullability, exclusive bounds, boolean `items`, multi-type arrays, `const`) are
  normalized; and the declared 3.1 boundaries (`webhooks` not generated from, tuple `prefixItems`,
  advanced JSON Schema applicators, `additionalProperties: false`). Factual, no open decision.
- **Versioning and upgrades** (`guides/versioning-policy.mdx`, issue #41, **DRAFT proposal**): what
  semver means for a code generator (the tool surface vs the output surface), the proposed
  breaking-change definition, the regenerate-on-upgrade flow, the drift-check interaction, and how
  0.x differs from the 1.0.0 output freeze. **Open decision (Benjamin):** (a) adopt the pragmatic
  freeze where intentional output changes are major-only but correctness patches may ship in a patch
  with an explicit changelog call-out, vs a stricter byte-frozen promise; (b) the support-class
  breaking-change wording depends on #40, so settle #40 first.
- **Runtime coupling** (`guides/runtime-coupling.mdx`, issue #40, **DRAFT proposal**): generated code
  currently imports four support classes (`HostnameRule`, `MultipleOfRule`, `Rfc3339DateTimeRule`,
  `MapObjectTransformer`) from the generator's `Support\` namespace, so the generator is a runtime
  dependency of every consuming app. **Open decision (Benjamin):** option A keep the runtime
  dependency, option B inline the support classes into the consumer's namespace (**recommended**,
  fully-owned output, no silent runtime change), or option C a tiny frozen runtime package. Must
  settle before 1.0.0 because the import namespace is part of the frozen output format, and it
  compounds with the discriminator cast (#38). Analysis only, nothing implemented.

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

Pest unit tests per feature (minimal in-memory spec builder) plus snapshots. Corpus: all 130
fixtures (128 original plus Sentry and Twilio v2010 added in 0.7.0) parse and generate valid PHP,
passing the import-resolution gate (260 corpus cases across the model and server gates), the `php -l`
compile gate (catches compile errors `token_get_all` misses), plus the allOf/additionalProperties/
oneOf non-empty guards. A synthetic OpenAPI 3.1 conformance fixture covers the full generator
surface, with a golden test (wired in 0.6.0) that pins the per-construct output, runs `php -l`, and
verifies byte-for-byte determinism. Hostile-input suite for the security surfaces. Behavioral
validator round-trips pin each 0.5.0 constraint (exclusive bounds, multipleOf, uniqueItems, float
enums, strict date-time, defaults). The differential validation oracle (0.7.0, issue #23) generates
a class per constraint and runs valid and invalid payloads through the real Laravel Validator; a
known-gap ratchet documents acknowledged gaps. Regression fixtures for the sibling-history edge
cases. Pest native mutation (>=90%), 100% type coverage, PHPStan max, Pint. The e2e demo adds
real-HTTP round-trip proofs across the language boundary, plus a Playwright headless-Chrome suite
that drives the full browser -> SPA -> generated client -> backend loop against a docker-compose
stack. Current totals: 580 package tests passing (the number fluctuates as parameterized corpus
suites are refactored; all gates green).

## Open questions (genuine design decisions)

- **Discriminator-aware object-union validation and hydration** (issue #38): **landed** for the
  named-component `oneOf`/`anyOf` + `discriminator` form (abstract morphable base plus variants, both
  validated and hydrated). The inline-union and allOf-inheritance discriminator forms remain
  presence-only with a build warning, tracked as a follow-up. Undiscriminated unions stay `mixed`
  (#31) by design.
- **`additionalProperties: false` enforcement** (issue #30): decide whether to emit a rule rejecting
  undeclared properties. Currently a documented limitation.
- **Component request bodies / responses as typed params**: resolve `$ref` to
  `#/components/requestBodies/...` into a typed Data parameter instead of the `Request` fallback.
- **Map-of-`$ref` value hydration**: a `$ref`-valued `additionalProperties` map is typed in the
  docblock but not auto-hydrated into Data objects at runtime.
- **Exact laravel-data v4 feature surface** for casts/transformers (dates, enums, nested + typed-map
  value hydration).

Resolved: allOf, additionalProperties (including empty-map `{}` encoding), oneOf/anyOf, non-object
component aliasing, the silent-validation gaps (0.5.0 sweep plus 0.7.0 oracle-driven fixes),
the parser boolean-items/OOM cases, the security surfaces, the two sibling-history bugs, the
array-of-union DataCollectionOf bug (#24), the drift-check enforcement layer, the differential
validation oracle (#23), nested-array depth rules (#28), string-typed scalar coercion (#32, #33),
per-property required diagnostic (#34), and hostname enforcement (#29) are all implemented. The
undiscriminated object-union false-reject is fixed (#31, interim). The cross-language contract loop
is proven end to end over real HTTP, including the browser-driven Playwright suite; discriminator-
aware object-union runtime hydration and `additionalProperties: false` enforcement are the
remaining open cases. Floors are PHP 8.2 + Laravel 11/12 + laravel-data v4.
