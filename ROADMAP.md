# Roadmap

Forward-looking only. Release-by-release history lives in `CHANGELOG.md` and git, not here. This
file holds the current state, the decisions already made, what remains before 1.0.0, and the genuine
open questions. Do not re-litigate decided items without new information.

## Status

**0.10.0 on Packagist; 0.11.0 queued** (release-please PR open). A plain `php artisan openapi:generate`
(or the framework-free `vendor/bin/openapi-laravel`) generates the full slice out of the box: Data
model classes with spec-derived `rules()`, native backed enums, one abstract controller per tag, and
a routes file. `--no-controllers` / `--no-routes` opt out; settings resolve with strict precedence
(CLI flags over config file over built-in defaults); the standalone binary reads an optional
`openapi-laravel.json`. Generated output is self-contained: the support classes a spec references are
inlined into the consumer's own `\Support` namespace, so there is no runtime dependency on the
generator. The drift gate (`openapi:check`) and the differential validation oracle are permanent
quality layers.

The version target stays deliberately in 0.x while the generated output format is still evolving.
1.0.0 (the output-stability promise) is tagged only when the format settles, not on a feature count.

## Vision

`codewithagents/openapi-laravel`: take an OpenAPI spec (the source of truth) and generate Laravel
models and a server scaffold from it. The sibling of `openapi-zod-ts` (same author, same philosophy:
owned output, readable generated code, zero magic) for the Laravel ecosystem.

Quality bar: valid, PHPStan-max-clean output for all 130 real-world specs in `tests/Fixtures/specs/`,
passing the import-resolution and `php -l` gates, before anything ships.

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
5. **Targets**: PHP 8.2+, Laravel 11/12/13 (13 needs PHP 8.3+), laravel-data v4 (^4.15 for the morphable discriminated unions).
6. Package name: `codewithagents/openapi-laravel`.
7. **The sibling history is a test oracle.** openapi-zod-ts's fixed bugs are a checklist of
   real-world edge cases to verify against this generator, independent of the corpus.
8. **Stay in 0.x now, 1.0.0 when the output format settles.**
9. **Versioning (pragmatic).** Intentional output-shape and validation changes are major-only;
   correctness patches (non-compiling output, a dropped or wrong constraint) may ship in a patch with
   an explicit changelog call-out. The inlined support classes are part of the output surface.
10. **`additionalProperties: false` enforced by default.** The spec is the source of truth, so a
    closed object rejects undeclared keys without a flag; `--no-enforce-closed-objects` is the opt-out.
11. **422 error shape: documented renderer recipe, not a generated renderer (#79).** The corpus
    shows no common error schema to generate against (FastAPI `HTTPValidationError`, GitHub
    `validation_failed`, RFC 9457 variants, flat `{code, message}`, inline shapes; many specs use
    400 instead of 422), and the error-bag-to-schema mapping is application semantics the spec does
    not encode. The generator contributes the typed half (error component schemas already generate
    Data classes); the docs guide `guides/validation-errors` holds the bootstrap renderer recipe.
12. **Config diet: the generator is opinionated about style.** New options must be environment
    facts the generator cannot know (paths, middleware names, FQCNs) or correctness escape
    hatches, never style preferences. Style is the generator's job. The #93 (`--group-by-tag` /
    `output.group_by_tag`) and #94 (`--laravel-conventions` / `controllers.laravel_conventions`)
    flags were removed accordingly: the tag-grouped data layout and the Laravel-convention method
    names are the only behavior. #96 closed as not planned under the same principle.

## Current capabilities

### Models [shipped]
laravel-data classes, spec-derived `rules()` (exclusive bounds, `multipleOf`, `uniqueItems`, strict
date/date-time/time/duration, hostname, defaults, `const`), backed enums, readOnly/writeOnly split,
nested objects, collections, `allOf` merge, `additionalProperties` typed maps (empty map serializes
as `{}`), `oneOf`/`anyOf` native unions for scalars, and discriminated object unions (all three forms:
named-component, inline-union with synthesized variant names, and allOf-inheritance) validated and
hydrated via an abstract morphable base plus variants. Default-on closed-object enforcement. The
naming layer (incl. the `this` special case). Deterministic, Pint-idempotent, PHPStan-max-clean,
Qodana-clean output. Artisan command plus framework-free `vendor/bin`. Validation messages and
attribute names are customizable without touching generated files (#83): `output.validation_trait`
(config-only, both surfaces) names a user-owned trait every generated Data class pulls in, the
sanctioned home for laravel-data's static `messages()` / `attributes()` hooks, proven against the
real Validator and regeneration-safe by test. Tag-grouped data layout (#93, the ONLY layout since
the config-diet decision; the flags and config key are deleted and the flat mode is gone): Data
classes and enums solely owned by one tag group land in per-tag subdirectories with namespaces
following the directories (`data/Pet/PetData.php`, `App\Data\Pet`), mirroring the controller
grouping. The model generator computes the attribution from the document itself on every run, so
every entry point emits the grouped tree. The deterministic attribution rule: an operation belongs
to its FIRST tag (the controller tag, untagged ops count as `Untagged`); a schema reachable,
through the same transitive walk as the subset closure, from operations of exactly one tag group
goes into that group; multi-group, unreferenced, and reserved-`Support`-tag schemas stay at the
flat root (no shared/ directory). Per-operation query and body classes follow their operation's
tag; nested and Writable classes follow their owner; the inlined Support classes stay in
`Support/`. Cross-group references are imported, and the drift gate verifies the grouped tree.

### Server scaffold [shipped, generated by default]
Generated routes plus one abstract controller per tag, typed by the Data classes. Unimplemented means
a PHP fatal. Path-level drift is structurally impossible. Every route carries a deterministic
`->name()` derived from its operationId (globally unique, suffixed on cross-controller clashes), and
the config-only `routes.middleware` / `routes.prefix` keys wrap the routes in one Route::group (#71).
PathItem-level parameters merge into every operation, and `$ref` parameters resolve through
components (#66). Query parameters (#63) generate a per-operation query Data class
(`<Operation>QueryData`) with spec-derived `rules()` through the exact body-class pipeline, plus a
`fromQuery(Request)` factory that validates and hydrates from the query string only. An inline JSON
object request body (#76) synthesizes a per-operation Data class (`<Operation>RequestData`, named
from the operationId like the query class, collision-safe through the shared allocator) through the
exact component-class pipeline (full `rules()`, nested objects, closed-object enforcement, the
write shape when readOnly fields exist) and types the controller param, so inline bodies validate
exactly like `$ref` bodies. A `multipart/form-data` object body (#75, inline or schema-`$ref`)
synthesizes the same per-operation class with `format: binary` root parts typed `UploadedFile`
(Laravel `file` rule, plus `mimetypes:` from a well-formed `contentMediaType`, incl. the `type/*`
wildcard form), arrays of binary items as `array<int, UploadedFile>` with per-item `field.*` file
rules, and every non-binary part validated like a JSON field. JSON wins when an operation declares
both media types, and a `$ref` multipart schema is re-emitted per operation rather than typed
against the component class (that class carries JSON semantics, whose `string` rule would
false-reject actual uploads). Hybrid
injection: body-less operations get the class type-hinted into the signature (laravel-data routes
container injection through `fromQuery`); operations with a request body get a docblock pointer to
`::fromQuery($request)` instead, so body and query inputs never bleed into each other. Boolean
parameters accept the form-style `true`/`false` literals (mapped to 1/0 before validation, so
hydration is correct too). The spec-declared success status is honored (#64): the selected success
response (smallest 2xx, the same pick that drives the return type) is enforced by the inlined
`RespondsWithStatus` route middleware when it is not 200, so a 201 create operation answers 201 out
of the box, and a selected 204 types the abstract method `void` with the middleware setting the
status and guaranteeing the empty body. The middleware only rewrites an exactly-200 response, so
error responses and explicitly set statuses pass through untouched. Security schemes map to
per-route middleware via the config-only `security.middleware_map` key (#77): operation `security`
overrides global, `security: []` keeps an operation public, AND requirements apply all mapped
middleware, OR alternatives enforce only the first requirement object (warned per operation), and a
required-but-unmapped scheme (or a mapped name the spec never declares) warns at generation time.
Laravel-convention method names (#94, the ONLY naming since the config-diet decision; the flags
and config key are deleted and the operationId-only mode is gone): a clean RESTful operation gets
index/show/store/update/destroy as its method AND route name (item path = last segment is a path
parameter); a conventional name claimed twice in one controller makes ALL claimants fall back to
the operationId-derived name (order-independent), non-CRUD operations always fall back, residual
clashes go through UniqueNames, route names stay globally unique (index, index_2, ...), and the
Data layer (incl. query classes) stays operationId-derived. `openapi:scaffold` (and `vendor/bin/openapi-laravel scaffold`, issue #78) writes
one-time concrete controller stubs extending the abstracts, every method an explicit
`throw new LogicException(...)` placeholder, so a fresh project boots right after generate +
scaffold; existing files are skipped (never overwritten) and the drift gate never sees a stub.
The abstracts extend nothing by default (framework-light by design); `controllers.base_class` (#83)
roots them in a configurable base class instead, in both config surfaces plus a
`--controller-base-class` flag on the standalone binary, validated as a legal FQCN with short-name
collisions failing loudly.

### Honest residuals (documented, not hidden)
- Undiscriminated object unions (no `discriminator`) stay `mixed`, presence-only by design (#31).
- An allOf-inheritance variant that does not pin its discriminator with a `const` is not rejected for
  a wrong value when validated STANDALONE (morph routing through the base is unaffected).
- A component `$ref` request body (`#/components/requestBodies/...`) falls back to
  `Illuminate\Http\Request` instead of a typed Data param. Inline JSON OBJECT bodies are generated
  (#76) and multipart/form-data OBJECT bodies too (#75); a body that is NOT an object shape (an
  array, scalar, union, enum, free-form map, or a whole-body binary multipart schema) keeps the
  Request fallback, warned per operation.
- Multipart residuals (#75): no file-size rule is derived (OpenAPI has no standard byte-size
  keyword; `maxLength` is a string bound and Laravel's file `max:` counts kilobytes, no clean
  mapping), the `encoding.contentType` map is not read (only schema-level `contentMediaType` feeds
  `mimetypes:`), a binary string nested BELOW the body root stays a plain string (it sits in a
  JSON-serialized part), and form-urlencoded bodies keep the Request fallback.
- Tuple `prefixItems` validates per position (#82: `field.0`, `field.1`, ... rules through the
  shared constraint mapping, plus a `max:` length cap for the closed `items: false` form) but the
  TYPING still degrades to `array<int, mixed>`; a post-prefix `items` schema stays unenforced (a
  `field.*` rule would false-reject the prefix positions).
- int64/bignum literal bounds, non-JSON responses, and `$ref`-valued map values degrade gracefully
  (typed in the docblock, not auto-hydrated).
- `in: header` / `in: cookie` parameters are not generated (warned per operation, #63 scoped them out).
- Query parameters without a flat `key=value` / `key[]=value` form are skipped with a warning:
  `deepObject` (Stripe's filter objects), `spaceDelimited`/`pipeDelimited`, non-exploded arrays,
  object-shaped and content-typed parameters.
- Array query parameters validate their elements against the spec type but hydrate them as the raw
  query strings (PHP query parsing produces strings; top-level scalars hydrate typed).
- Only the SELECTED success response (smallest 2xx) is typed and status-enforced (#64); alternative
  declared 2xx statuses on the same operation stay the implementer's responsibility (the middleware
  never overrides an explicitly set non-200 status).

### Client generation [maybe, decide later]
Generate a typed PHP client for *consuming* a third-party or internal API from its spec, built on the
`Http::` facade. Not a near-term commitment.

## Toward 1.0.0

The enforcement and correctness layers are in place: full output by default, drift as a CI gate, the
differential oracle guarding every emitted constraint, self-contained output (no runtime coupling),
and a clean large-corpus sweep. The remaining gate to 1.0.0 is **output-format stability**: hold the
format steady across a few releases, then tag 1.0.0 as the output-stability promise.

The three 1.0.0-readiness docs are finalized and live under `docs/src/content/docs/guides/`:
supported OpenAPI versions (`openapi-versions.mdx`), versioning and upgrades (`versioning-policy.mdx`),
and runtime coupling (`runtime-coupling.mdx`).

Remaining smaller follow-ups (not blockers):
- Richer error messages on bad input.
- `$ref`-valued request bodies as typed Data params instead of the `Request` fallback.
- `@deprecated` docblocks on abstract controller methods for deprecated operations.
- Cross-repo: the openapi-zod-ts `Accept: application/json` gap (openapi-zod-ts #289), surfaced by the
  e2e demo. The fix lives in the sibling repo, not here.

**What 1.0.0 means:** at the cutover, flip `enforce_admins: true`, require 1 PR review, set required
checks `strict: true`, and move from the direct-push flow to a PR + review workflow.

## Open questions (genuine design decisions)

- **Component request bodies / responses as typed params**: resolve `$ref` to
  `#/components/requestBodies/...` into a typed Data parameter instead of the `Request` fallback.
- **Map-of-`$ref` value hydration**: a `$ref`-valued `additionalProperties` map is typed in the
  docblock but not auto-hydrated into Data objects at runtime.
- **Exact laravel-data v4 feature surface** for casts/transformers (dates, enums, nested and
  typed-map value hydration).
- **Client generation**: whether to build the consuming-client direction at all (see above).

## Security posture

The spec is untrusted input and the generated PHP is loaded and executed by the host. Defenses:
docblock-injection neutralization, an identifier whitelist (path traversal, identifier injection, the
`this` fatal), escaped literals, validated namespace options, structural rejection of non-OpenAPI
documents, an input-size guard plus documented OS-limit guidance for the YAML-alias-bomb residual,
operator-controlled CLI output paths, config-file output-path containment (#54), and a hostile-input
regression suite. Config containment: the standalone binary auto-discovers `openapi-laravel.json` from
the working directory, so every config-sourced write path (`output.path`, `controllers.path`,
`routes.path`) is contained by `PathContainment` to the config's directory; a `..` traversal, an
absolute escape, or a symlinked-parent escape fails closed before any write. CLI flags keep full
freedom by design. Residual: YAML alias expansion happens inside the vendored parser, so the size
guard plus OS limits are the mitigation.

## Repo and release governance

- **Now (pre-1.0.0):** direct commits to `main`, push on maintainer approval. Branch protection blocks
  force-push and deletion, restricts push to the maintainer, and requires CI status checks;
  `enforce_admins` is off and PR reviews are not required, so the direct-push flow works.
- **At 1.0.0:** flip `enforce_admins: true`, require 1 PR review, set required checks `strict: true`,
  move to a PR + review workflow.

## Test strategy

Pest unit tests per feature (a minimal in-memory spec builder) plus snapshots. Corpus: all 130
fixtures parse and generate valid PHP, passing the import-resolution gate (model and server gates),
the `php -l` compile gate, and the allOf/additionalProperties/oneOf non-empty guards. A synthetic
OpenAPI 3.1 conformance fixture covers the full generator surface with a golden test that pins the
per-construct output and verifies byte-for-byte determinism. The differential validation oracle (#23)
generates a class per constraint and runs valid and invalid payloads through the real Laravel
Validator, with a known-gap ratchet documenting acknowledged gaps (currently the single by-design
undiscriminated-union gap, #31). The query-parameter oracle (#63) drives the generated `fromQuery`
through real Request objects (payloads serialized to a query string and parsed back), so spec-valid
queries must accept and spec-invalid queries must reject through the actual wire path. Two generated-output gates assert the emitted code is Pint-idempotent
and PHPStan-max-clean; both are tagged `slow` (see CLAUDE.md "Test workflow"). Hostile-input suite for
the security surfaces. Pest native mutation (>=90%), 100% type coverage, PHPStan max, Pint, Deptrac,
composer-unused / composer-require-checker, `composer audit`, and Qodana run in CI. The e2e demo adds
real-HTTP round-trip proofs across the language boundary, plus a Playwright headless-Chrome suite over
a docker-compose stack. The suite is ~1230 tests green (the number fluctuates as parameterized corpus
suites are refactored; all gates green).
