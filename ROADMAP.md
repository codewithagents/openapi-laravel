# Roadmap

Status: **0.1.1 released on Packagist.** The v1 models generator is shipped end to end. All seven
v1 phases are done: parser, naming, models emitter, rules() emitter, artisan command, standalone
bin, readOnly/writeOnly split, docs site, and the release pipeline (release-please + Packagist).
All 128 corpus specs parse and generate valid PHP, with PHPStan max, 100% type coverage, Pest
mutation >=90%, dependency hygiene, and Testbench round-trip tests green. 0.1.1 also added a
`.gitattributes` so the published dist ships only runtime code (~28 KB) instead of the full repo.

The next milestone is **0.2.0: production-ready**, the work that makes the package safe to
recommend to real Laravel shops. See "Road to production-ready" below. This file is the resume
point for the next session.

## Vision

`codewithagents/openapi-laravel`: a composer package that takes an OpenAPI spec (the source of
truth) and generates Laravel models from it. The sibling of `openapi-zod-ts` (same author, same
philosophy: owned output, readable generated code, zero magic) for the Laravel ecosystem.

Quality bar: the generator must produce valid, PHPStan-max-clean output for all 128 real-world
specs in `tests/Fixtures/specs/` before anything ships.

## Market context (researched 2026-06-10)

- Laravel: 535M Packagist installs, 64% PHP framework share (JetBrains 2025), growing.
- Code-first spec generation is crowded (l5-swagger 36.8M DLs, dedoc/scramble 10.3M DLs). Do NOT compete there.
- Spec-first (spec -> code) is nearly empty: ensi-platform/laravel-openapi-server-generator (15 stars,
  custom OAS extensions), rogervila/openapi-laravel (obscure). Drift/CI tooling: fissible/drift (0 stars,
  brand new). Optic archived Jan 2026, no OSS replacement.
- Demand signals: apisyouwonthate.com contract-testing article literally asks someone to build this;
  Bump.sh ships a design-first Laravel guide; Laravel News covers the category.

## Decisions made (do not re-litigate without reason)

1. **True PHP package**, not an emitter inside the openapi-zod-ts monorepo. Better adoption,
   idiomatic distribution (`composer require`, artisan command). Accepted cost: own parser layer
   and own test harness in PHP.
2. **Parser: do not write one.** Use `devizzent/cebe-php-openapi` (maintained fork of
   cebe/php-openapi): parses OpenAPI 3.0/3.1 from YAML/JSON, validates, resolves $refs.
   Wrap it behind our own `Parser` namespace so it stays swappable.
3. **Output style: spatie/laravel-data classes** (the de-facto Laravel DTO standard, 12M+ DLs)
   with **explicit, spec-derived `rules()` methods** so OpenAPI constraints (maxLength, pattern,
   format, enum) are enforced verbatim, not inferred from property types. Plus native PHP 8.1+
   backed enums for spec enums. Plain-PHP-classes output = possible later config flag, not v1.
4. **Spec fixtures are duplicated** from openapi-zod-ts (they are public artifacts, not a contract).
   A shared public spec-fixture repo is a maybe-later idea. Future: accept spec contributions from
   Laravel shops that publish OpenAPI specs.
5. **Targets**: PHP 8.2+, Laravel 11/12, laravel-data v4. (Confirm exact floors in phase 1.)
6. Package name: `codewithagents/openapi-laravel` on Packagist.

## Versions

### v1: Models (the package)

Spec in -> generated `app/Data/*` (configurable namespace/path):

- One laravel-data class per `components.schemas` entry + per inline request/response shape.
- Explicit `rules()` per class, derived from spec constraints (required, nullable, minLength,
  maxLength, pattern, format: email/uuid/date-time, minimum/maximum, enum via `Rule::enum`).
- Native backed enums for string/int enums.
- readOnly/writeOnly handling: split read/write variants like openapi-zod-ts does
  (`Customer` vs `CustomerWritable`) only when the spec actually uses the flags.
- Nested objects -> nested Data classes; arrays -> `DataCollection`/typed arrays with docblocks.
- Naming layer: StudlyCaps classes, camelCase properties with `#[MapName]` attributes when the
  wire name differs (snake_case etc.), collision handling, PHP reserved-word escaping.
- Invocation: `php artisan openapi:generate` (via service provider) AND a plain `vendor/bin`
  entry so non-Laravel CI can run it. Config file: `openapi-laravel.php` (publishable).
- Determinism: same spec in, byte-identical output (stable ordering everywhere).

### v2: Server scaffold (the differentiator, nothing credible exists in the ecosystem)

- Generated routes file (`routes/api.generated.php`): one `Route::` entry per spec operation,
  pointing at controller classes by tag + operationId.
- Generated abstract controller per tag: one abstract method per operation, typed by the v1 Data
  classes (params + request body in, Data response out). User extends with concrete controllers,
  delegates business logic to services. Unimplemented = PHP fatal, not silent drift.
- This makes path-level spec/code drift structurally impossible: the routing table derives from
  the spec. (Spring-generator pattern, translated to idiomatic Laravel.)

### v3 (maybe): client generation (`Http::` facade based). Less differentiated, decide later.

## Phase plan for v1 (all shipped in 0.1.0 / 0.1.1)

1. **Skeleton compiles** [done]: composer.json deps resolve, Pest runs, PHPStan max, CI matrix
   (php 8.2/8.3/8.4 x lowest/highest). Floors: PHP 8.2, Laravel 11/12, laravel-data v4.
2. **Parser layer** [done]: wraps cebe (`devizzent/cebe-php-openapi`), lazy refs (no eager
   resolution, DoS-safe), depth bound, format detection. All 128 fixtures parse.
3. **Naming layer** [done]: StudlyCaps classes, camelCase properties, `#[MapName]`, reserved-word
   escaping, collision suffixing, nested-name de-duplication.
4. **Models emitter** [done]: schemas -> Data classes + native enums + nested + collections.
   Snapshot tests per feature; corpus generate gate (128) with `php -l` + PHPStan max on output.
5. **rules() emitter** [done]: required/nullable, string/numeric/array constraints, format rules,
   regex, `Rule::enum` / `Rule::in`, readOnly/writeOnly read+write variants.
6. **Artisan command + config + docs** [done]: `openapi:generate`, publishable config, standalone
   bin, README with comparison table, docs site live at https://openapi-laravel.codewithagents.de.
7. **Release** [done]: release-please (`release-type: php`), Packagist webhook + explicit CI
   notify, conventional commits. v0.1.0 then v0.1.1 published.

## Road to production-ready (0.2.0)

The generator works on all 128 corpus specs, but "generates valid PHP" is not the same as "a
Laravel team can adopt it without surprises". 0.2.0 closes that gap. Roughly in priority order:

1. **Packaging hygiene** [done in 0.1.1]: `.gitattributes export-ignore` so the dist ships only
   runtime code. Verified: published dist is ~28 KB, src+bin+config only.
2. **Verified getting-started path**: stand up a throwaway Laravel app, `composer require` the
   published package (not the local checkout), generate from a real spec, and assert the output
   compiles and validates. This is the one test that proves the *published artifact* works, not
   just the repo. Candidate for a scheduled CI job against the live Packagist release.
3. **Generator resilience on hostile/odd specs**: today the corpus is "good" specs. Add cases for
   the failure modes a real user hits: empty schemas, `$ref` to missing component, circular refs
   beyond the depth bound, schema-less `additionalProperties: true`, properties named like PHP
   keywords/magic methods, unicode property names. Each should produce a clear error or a
   documented, sensible output, never a fatal or silent-wrong file.
4. **oneOf / anyOf / allOf** (the big open design item): decide and implement a representation.
   allOf = merge (probably straightforward). oneOf/anyOf = union types vs abstract base + variants
   vs a documented "emitted as mixed with a discriminator note". Whatever ships must be in the
   docs with examples. This is the most likely reason a real spec generates something unsatisfying
   today.
5. **`additionalProperties` policy**: document and implement the decision (ignore unknown keys vs
   emit an explicit prohibition). Currently undocumented behavior.
6. **DX on bad input**: actionable error messages (which schema, which property, what was wrong),
   a `--dry-run` / summary of what would be written, and a non-zero exit on partial failure so CI
   can gate on it.
7. **Docs completeness**: the site exists but should cover config reference, the read/write variant
   model, the rules() mapping table (spec constraint -> Laravel rule), and a "known limitations"
   page (oneOf/anyOf, additionalProperties). Honesty about limits builds trust for adoption.
8. **README accuracy pass**: the "Why quality matters" section still cites Infection; we ship Pest
   native mutation. Align the docs with what the pipeline actually runs.

Stretch (could be 0.2.x or later, not blockers): a committed showcase-subset of generated output
diff-checked in CI (drift guard), and accepting community spec fixtures.

## Test strategy (mirror what worked in openapi-zod-ts)

- Unit: Pest snapshot tests per emitter feature (`makeSpec()`-style minimal spec builder helper).
- Corpus: all 128 fixtures must generate without errors; output must pass `php -l` and PHPStan max.
  13-spec showcase subset with committed output, diff-checked in CI (drift guard on the generator).
- Later: mutation testing (Infection, the PHP Stryker), runtime round-trip tests
  (instantiate generated Data from fixture payloads inside an Orchestra Testbench app).

## Open questions (genuine design decisions, mostly for 0.2.0)

- **oneOf/anyOf representation in PHP**: union types? abstract base + variants? a documented
  `mixed` + discriminator note? laravel-data has partial union support, research first. This is
  the headline 0.2.0 design question (item 4 above).
- **additionalProperties policy**: `false` -> Laravel has no native "reject unknown keys" on nested
  arrays; `true`/schema -> how to type it. Decide ignore vs explicit prohibition vs typed bag.
- **Exact laravel-data v4 feature surface** for casts/transformers we should lean on vs emit by
  hand (dates, enums, nested hydration edge cases).

Resolved since the first draft: floors are PHP 8.2 + Laravel 11/12 + laravel-data v4; the standalone
`vendor/bin` mode uses a slim framework-free `StandaloneApplication` (no illuminate/console needed).
