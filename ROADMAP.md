# Roadmap

Status: **0.2.0 released on Packagist; 0.3.0 in progress on main (unreleased, a large local
commit stack, nothing pushed).** The v1 models generator and the v2 server scaffold are shipped.
0.3.0 has grown well beyond its original "composition keywords" scope into a correctness and
security hardening release. Done and committed locally toward 0.3.0:

- **`allOf` merging** (member schemas flattened into the composed class), plus a hardening pass.
- **`additionalProperties`** represented as typed maps with per-value rules.
- **Security hardening** of the whole generator against untrusted spec input (8 findings fixed).
- **Edge-case fixes mined from the openapi-zod-ts sibling history** (two real bugs the 128-spec
  corpus never exercised), plus a stronger corpus gate that catches that whole bug class.
- **Repo governance**: branch protection on `main`, `FUNDING.yml`.

The only original-scope 0.3.0 item still open is **`oneOf`/`anyOf`** (still typed as `mixed` by
design). This file is the resume point for the next session.

## Done and committed toward 0.3.0 (unreleased, local on `main`)

### Composition keywords

- **allOf merge.** Member schemas are merged into a single flat Data class: inline objects and
  `$ref`s are resolved recursively (including nested allOf and the schema's own `properties`),
  `properties` unioned, `required[]` concatenated and deduped, `rules()` covering the combined
  shape. Conflict rule: later member overrides earlier, own-level property overrides every member,
  first-seen position kept for ordering. A `$ref` member still emits its own standalone class too.
  The single-`$ref`-wrapped-in-allOf alias pattern resolves to the referenced class, which also
  breaks self-referential allOf cycles (found in jira.json). Impact: 863 classes that were empty
  due to allOf across 25 corpus specs are now populated.
- **allOf hardening (review-driven).** Nullable is unioned across the composing schema and all
  members (was a real bug: nullability read only the top-level schema). Read/write variant
  detection scans properties contributed by allOf members recursively, so a `writeOnly`/`readOnly`
  flag on a base schema correctly drives the write-variant split and the server scaffold's
  write-class wiring. A corpus non-empty guard (`tests/Corpus/AllOfNonEmptyTest.php`) asserts
  curated allOf-composed classes carry a constructor, so a regression cannot silently re-empty
  them.
- **additionalProperties as typed maps.** Three value forms: scalar value schema ->
  `array<string, int>` plus a `'field.*'` wildcard value rule reusing the rule logic; `true` ->
  `array<string, mixed>`, no value rule; `$ref` value -> `array<string, FooData>` plus
  `'field.*' => ['array']` (values arrive as raw arrays, documented honestly). Pure-map components
  (object with only `additionalProperties`) are not emitted as a class; the array type is inlined
  at every `$ref` use site, which kills the empty-class bug deterministically. Mixed objects
  (named `properties` plus `additionalProperties`) emit the named properties correctly with a
  documented note that dynamic overflow keys are not captured (laravel-data cannot route unknown
  keys without a custom cast). `additionalProperties: false` is not enforced (Laravel has no
  reject-unknown-keys rule), documented. Impact: 128 pure-map components across 20 corpus specs
  that previously emitted empty classes are now represented.

### Security hardening (spec treated as untrusted input)

A security review modelled the OpenAPI spec as untrusted input (the generator writes PHP source
the host then loads and executes). All findings fixed:

- **Docblock code injection (HIGH).** Operation `summary` and `path` were written raw into a
  generated controller docblock, so a `*/` in the spec closed the comment and injected live PHP.
  Fixed with a `docblockSafe()` helper that rewrites `*/` to `* /` and collapses control chars,
  applied to every spec string placed in a comment.
- **Namespace/suffix injection (MED).** `--namespace` / `--suffix` / `--controller-namespace`
  (and config equivalents) flowed unvalidated into generated `namespace` declarations. Now
  validated against legal-PHP-identifier regexes before any file is written, non-zero exit on bad
  input (`OptionValidator`).
- **regexRule silently dropped patterns (MED).** A `pattern` containing both `#` and `~` produced
  no regex rule at all (silent under-validation). Now iterates a candidate-delimiter list with a
  fixed-delimiter-plus-escaping fallback, so a rule is never dropped.
- **Non-OpenAPI doc accepted silently (the "invalid spec" answer).** A Swagger 2.0 or non-OpenAPI
  document parsed to an empty result and exited 0. Now a lightweight post-parse check requires an
  `openapi: 3.x` version and a present `info`, with a clear error naming what was found. Malformed
  YAML and missing/unreadable files already failed cleanly via `ParseException`; this closes the
  structurally-wrong-but-valid-YAML hole. Stays on `validate: false` so the corpus is unaffected.
- **YAML alias bombs (MED).** Symfony YAML expands anchors before we see the data. Added a
  configurable input-size guard (`--max-bytes` / config), default 24 MiB, comfortably above the
  largest fixture (github.json, ~11.1 MiB). Residual documented: untrusted specs still warrant
  OS-level resource limits.
- **Quadratic allOf cycle guard (LOW).** The `seen` set is now an associative array (O(1)).
- **Hostile-input tests.** A dedicated suite feeds comment-breaking names, quoted/backslashed enum
  values, `*/`-laden summaries/paths, and `#`+`~` patterns, asserting generated files parse, no
  unneutralized `*/` survives, the injected payload is only ever inside comment tokens, and the
  option validators reject hostile input. Path traversal via schema names was already defended by
  the `PhpIdentifier` whitelist; the `--prune` glob is confined; enum values / wire names /
  defaults were already single-quote escaped.

### Edge-case fixes mined from openapi-zod-ts history

The sibling TypeScript generator's git history holds years of fixes for cases the shared corpus
never exercised. Mining it surfaced two real bugs in this generator that the syntax-only corpus
gates were blind to:

- **`$ref` request body missing import (HIGH, corpus-present).** When a request body was a `$ref`
  to `#/components/requestBodies/...`, the operation emitted `Request $request` but did not import
  `Illuminate\Http\Request`, so 72 generated controllers across 14 corpus specs (bitbucket, slack,
  square, xero, zoom, sendgrid, docusign, and more; 331 operations) referenced a non-existent
  class. Fixed by adding the import on that path.
- **Property named `this` (HIGH, latent).** A property literally named `this` produced
  `public readonly $this`, a hard PHP fatal. `this` is the one reserved name forbidden as a
  parameter. Now remapped to `_this` with `#[MapName('this')]` preserving the wire key.
- **`const` ignored (LOW/MED).** A schema `const` is now treated as a single-value enum
  (`Rule::in([value])`), with type inferred for a bare const. Previously dropped to `mixed`.

### Corpus gate strengthening (highest-leverage)

Both bugs above were invisible because the corpus gates only ran `token_get_all(..., TOKEN_PARSE)`,
which passes on syntactically-valid-but-unresolvable class references and on the `$this` fatal.
Added an **import-resolution gate**: for every generated file it collects `use` imports and the
class names defined across the generated set, then asserts every class short-name used in a
signature is imported, defined locally, or a scalar/pseudo-type from a minimal allowlist (framework
classes like `Request`/`JsonResponse`/`DataCollection` are deliberately NOT allowlisted, so the
gate genuinely catches the unimported-class bug class). Verified adversarially: reverting the
import fix makes the gate fail loudly, the fix makes it green across all 128 specs. Wired into both
the model and server corpus gates (256 corpus cases). Regression-guard fixtures also pin the
already-correct behaviour for 204-co-existing-with-200, nullable enums, and non-JSON responses.

### Repo governance

- **Branch protection on `main`** (live): required status checks (the PHP test matrix + static
  analysis), force-push and deletion blocked, push restricted to the maintainer, conversation
  resolution required. Deliberately `enforce_admins: false` with no required PR reviews yet, so the
  pre-1.0.0 direct-push workflow keeps working. See "Repo and release governance" below for the
  1.0.0 flip.
- **`FUNDING.yml`** added (GitHub Sponsors: `codewithagents`).

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
   cebe/php-openapi). Wrap it behind our own `Parser` namespace so it stays swappable. We parse
   with `validate: false` and lazy `$ref` resolution (no eager resolution, DoS-safe) plus our own
   lightweight structural check.
3. **Output style: spatie/laravel-data classes** with **explicit, spec-derived `rules()` methods**
   so OpenAPI constraints (maxLength, pattern, format, enum) are enforced verbatim, not inferred
   from property types. Plus native PHP backed enums for spec enums.
4. **Spec fixtures are duplicated** from openapi-zod-ts (public artifacts, not a contract). Future:
   accept spec contributions from Laravel shops that publish OpenAPI specs.
5. **Targets**: PHP 8.2+, Laravel 11/12, laravel-data v4.
6. Package name: `codewithagents/openapi-laravel` on Packagist.
7. **The sibling history is a test oracle.** openapi-zod-ts's fixed bugs are a checklist of
   real-world edge cases to verify against this generator, independent of the corpus.

## Versions

### v1: Models (the package) [shipped]

Spec in -> generated `app/Data/*` (configurable namespace/path):

- One laravel-data class per `components.schemas` entry plus per inline request/response shape.
- Explicit `rules()` per class, derived from spec constraints (required, nullable, minLength,
  maxLength, pattern, format, minimum/maximum, enum via `Rule::enum`/`Rule::in`, `const`).
- Native backed enums for string/int enums.
- readOnly/writeOnly read/write variant split, only when the spec uses the flags.
- Nested objects, typed collections, allOf merge, additionalProperties maps.
- Naming layer: StudlyCaps classes, camelCase properties with `#[MapName]`, collision handling,
  PHP reserved-word escaping (including the `this` special case).
- Invocation: `php artisan openapi:generate` and a framework-free `vendor/bin/openapi-laravel`.
- Determinism: same spec in, byte-identical output.

### v2: Server scaffold [shipped in 0.2.0]

- Generated `routes/api.generated.php`: one `Route::` entry per operation, pointing at controllers
  by tag + operationId.
- Generated abstract controller per tag, one abstract method per operation, typed by the v1 Data
  classes. Unimplemented = PHP fatal, not silent drift. Path-level drift is structurally
  impossible.

**Remaining v2 limitation:** a request body expressed as a component reference
(`$ref: '#/components/requestBodies/...'`) falls back to a plain `Illuminate\Http\Request`
parameter (now correctly imported, the missing-import bug is fixed) instead of a typed Data class.
Resolving component request bodies to a typed parameter is a candidate follow-up; referencing the
schema under `content` already yields a typed parameter.

### v3 (maybe): client generation (`Http::` facade based). Less differentiated, decide later.

## Remaining work toward tagging 0.3.0

1. **oneOf / anyOf** (28 / 24 specs): the last original-scope composition item. Still typed as
   `mixed` with presence-only rules. Plan: emit union type hints where all members resolve to known
   PHP types (`AData|BData`, `string|int`) and a docblock listing variants; keep presence rules.
   Honest residual: enforcing "exactly one of these shapes" at request-validation time requires a
   spec `discriminator`. Without one, PHP and Laravel cannot select among overlapping object
   variants, and laravel-data cannot know which Data class to hydrate. That residual is the spec
   author's responsibility, not a generator gap. This is the headline remaining design item.
2. **DX on bad input**: a `--dry-run` summary of what would be written, and richer error messages
   (which schema, which property, what was wrong). The structural validation and option validation
   already give clear failures; this is the polish layer.
3. **Lower-severity lossy cases (document or improve, not blockers):** tuple `prefixItems`
   collapses to `array<int, mixed>`; int64/bignum bounds are emitted as literal strings; non-JSON
   responses fall back to `JsonResponse`; `$ref`-valued map values are typed in the docblock but
   arrive as raw arrays; mixed-object overflow keys are not captured. All are deterministic and
   documented.
4. **Published-artifact CI job**: `composer require` the published Packagist release (not the local
   checkout) and run a smoke generate, to catch packaging-only breakage.
5. **Tag 0.3.0** via release-please once the above settle. The local stack is conventional-commit
   clean, so the changelog and version bump are automatic on push + merge.

## Security posture

The spec is untrusted input; generated PHP is loaded and executed by the host. Current defenses:

- Docblock injection neutralized; spec strings in comments are sanitized.
- Identifier whitelist (`PhpIdentifier`) prevents path traversal and identifier injection via
  schema/property/tag names; the `this` fatal is handled.
- Enum values, wire names, defaults, and regex patterns are escaped into PHP literals.
- Namespace/suffix options validated before any write.
- Structural validation rejects non-OpenAPI documents with a clear error.
- Input-size guard plus documented OS-level-limit guidance for the YAML-alias-bomb residual.
- Output paths are operator-controlled by design and documented as such (never derive them from
  untrusted input).
- A hostile-input regression suite locks all of the above.

Residual (documented, not code-fixable here): a determined attacker who fully controls the CLI
invocation or config can still choose hostile output paths; and YAML alias expansion happens inside
the vendored parser, so the size guard plus OS limits are the mitigation, not a hard cap.

## Repo and release governance

- **Now (pre-1.0.0):** direct commits to `main`, push on maintainer approval. Branch protection
  blocks force-push/deletion, restricts push to the maintainer, and requires the CI status checks,
  but `enforce_admins` is off and PR reviews are not required, so the direct-push flow works.
- **At 1.0.0 (the cutover):** flip `enforce_admins: true`, add `required_pull_request_reviews`
  (1 approval), set required status checks `strict: true` (up-to-date branches), and move to a
  PR + review workflow. This is a one-command protection change when we get there.

## Test strategy

- Unit: Pest tests per emitter feature using a minimal in-memory spec builder, plus snapshot tests.
- Corpus: all 128 fixtures must parse and generate valid PHP, now also passing the import-resolution
  gate (256 corpus cases across the model and server gates). Plus the allOf and additionalProperties
  non-empty guards.
- Hostile-input suite for the security surfaces; regression fixtures for the sibling-history edge
  cases (204+200, nullable enum, non-JSON response, `$ref` request body, `const`, `this`).
- Pest native mutation testing (>=90%), 100% type coverage, PHPStan max, Pint.
- Current totals: 563 tests passing locally.

## Open questions (genuine design decisions)

- **oneOf/anyOf representation in PHP**: union types where members resolve, a docblock variant list,
  and a documented discriminator requirement for runtime selection. laravel-data has partial union
  support, confirm the exact surface. This is the headline remaining 0.3.0 design question.
- **Component request bodies / responses as typed params**: resolve `$ref` to
  `#/components/requestBodies/...` into a typed Data parameter instead of the `Request` fallback.
- **Exact laravel-data v4 feature surface** for casts/transformers we should lean on vs emit by
  hand (dates, enums, nested hydration, typed-map value hydration).

Resolved: allOf merging, additionalProperties representation, the security surfaces, and the two
sibling-history bugs are all implemented (local on `main`, unreleased). Floors are PHP 8.2 +
Laravel 11/12 + laravel-data v4; the standalone `vendor/bin` mode uses a slim framework-free
`StandaloneApplication`.
