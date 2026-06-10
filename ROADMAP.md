# Roadmap

Status: v1 models generator working end to end. Phases 1-5 plus the artisan command, the
standalone bin, and the readOnly/writeOnly split are done and committed; all 128 corpus specs
parse and generate valid PHP, with PHPStan max, 100% type coverage, and Testbench round-trip
tests green. Remaining before the 0.1.0 tag: dependency-hygiene + mutation thresholds, the docs
site, and the release itself. This file is the resume point for the next session.

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

## Phase plan for v1

1. **Skeleton compiles**: composer.json deps resolve, Pest runs, PHPStan max passes on empty src,
   GitHub Actions CI (php 8.2/8.3/8.4 matrix). Decide exact version floors.
2. **Parser layer**: wrap cebe; normalize 3.0 `nullable` -> 3.1 union style once (mirror
   openapi-zod-ts `normalize-nullable`); recursion depth bound (DoS protection, mirror
   `schema-depth`); corpus smoke test: all 128 fixtures parse.
3. **Naming layer** + tests (port the ideas from openapi-zod-ts `src/utils/naming.ts`: dots,
   spaces, kebab-case, collisions; add PHP reserved words, namespaces).
4. **Models emitter**: schemas -> Data classes + enums. Snapshot tests (Pest) per feature,
   then corpus test: generate all 128, `php -l` + PHPStan max on output. This is the long phase.
5. **rules() emitter**: constraints -> Laravel validation arrays. Edge cases: oneOf/anyOf
   (union types + manual discriminator note), allOf (merge), additionalProperties
   (document the strictness decision), default values.
6. **Artisan command + config + docs**: README with comparison table (vs ensi-platform,
   rogervila, hand-writing), usage, philosophy section (mirror openapi-zod-ts README style).
   Docs site: GitHub Pages is already enabled on the repo; domain
   https://openapi-laravel.codewithagents.de already exists, wire Pages to it.
7. **Release**: Packagist + tags, conventional commits, GitHub release workflow.
   (Release Please works for PHP too with `release-type: php`; Packagist auto-updates via webhook.)

## Test strategy (mirror what worked in openapi-zod-ts)

- Unit: Pest snapshot tests per emitter feature (`makeSpec()`-style minimal spec builder helper).
- Corpus: all 128 fixtures must generate without errors; output must pass `php -l` and PHPStan max.
  13-spec showcase subset with committed output, diff-checked in CI (drift guard on the generator).
- Later: mutation testing (Infection, the PHP Stryker), runtime round-trip tests
  (instantiate generated Data from fixture payloads inside an Orchestra Testbench app).

## Open questions for next session

- oneOf/anyOf representation in PHP: union types? abstract base + variants? laravel-data has
  partial union support, research first.
- additionalProperties: false -> Laravel validation has no native "reject unknown keys" on nested
  arrays; decide policy (ignore vs generate explicit prohibition).
- Exact laravel-data v4 feature surface for casts/transformers we should lean on vs emit by hand.
- Floors: PHP 8.2 vs 8.3 minimum, Laravel 11 vs 12 minimum.
- Does `vendor/bin` standalone mode need illuminate/console bootstrapping or a slim symfony/console entry?
