# Roadmap

Forward-looking only. Release-by-release history lives in `CHANGELOG.md` and git, not here. This
file holds the current state, the decisions already made, what remains before 1.0.0, and the genuine
open questions. Do not re-litigate decided items without new information.

## Status

**0.12.0 on Packagist; 0.13.0 queued** (release-please PR open). A plain `php artisan openapi:generate`
(or the framework-free `vendor/bin/openapi-laravel`) generates the full slice out of the box: Data
model classes with spec-derived `rules()`, native backed enums, one abstract controller per tag, a
routes file, and the fidelity report (`openapi-laravel.unsupported.json`). `--no-controllers` /
`--no-routes` / `--no-unsupported-report` opt out; settings resolve with strict precedence (CLI
flags over config file over built-in defaults); the standalone binary reads an optional
`openapi-laravel.json`. Generated output is self-contained: the support classes a spec references
are inlined into the consumer's own `\Support` namespace, so there is no runtime dependency on the
generator. The drift gate (`openapi:check`) and the differential validation oracle are permanent
quality layers.

The version target stays deliberately in 0.x while the generated output format is still evolving.
1.0.0 (the output-stability promise) is tagged only when the format settles, not on a feature count.

## Vision

`codewithagents/openapi-laravel`: take an OpenAPI spec (the source of truth) and generate Laravel
models and a server scaffold from it. The sibling of `openapi-zod-ts` (same author, same philosophy:
owned output, readable generated code, zero magic) for the Laravel ecosystem.

Quality bar: valid, PHPStan-max-clean output for all 135 real-world specs in `tests/Fixtures/specs/`
(130 shared with openapi-zod-ts plus 5 OpenAPI 3.2 fixtures, #104 T8), passing the import-resolution
and `php -l` gates, before anything ships.

## Market context (researched 2026-06-10)

- Laravel: 535M Packagist installs, 64% PHP framework share. Code-first spec generation is crowded
  (l5-swagger, dedoc/scramble), do NOT compete there. Spec-first (spec -> code) is nearly empty:
  ensi-platform/laravel-openapi-server-generator (custom OAS extensions), rogervila/openapi-laravel
  (obscure). Optic archived Jan 2026, no OSS replacement. This is the open lane.

## Decisions made (do not re-litigate without reason)

1. **True PHP package**, not an emitter inside the openapi-zod-ts monorepo.
2. **Parser: our own minimal reader (#104, shipped).** Originally `devizzent/cebe-php-openapi`
   wrapped behind the `Parser` namespace; replaced by the internal `OpenApiReader` hydrating the
   read-only typed `Parser\Spec` value-object graph. The schema normalization rewrites are folded
   into the reader, 3.1 keywords are first-class typed properties (no `getSerializableData()`
   escapes), 3.2 fixed fields are stubbed (feeding #102), and lazy `$ref` resolution, the depth
   bound, the size guard, and the structural check are preserved. The swap was proven byte-identical
   for all 130 corpus specs against the frozen v0.11.0 baseline (`ReaderCorpusBaselineTest`, kept as
   a permanent gate; the 5 OpenAPI 3.2 fixtures added afterwards in #104 T8 are exempt by name since
   v0.11.0 never saw them, covered by `OpenApi32CorpusTest` instead); the cebe dependency is removed
   from composer.json. Not breaking for users: the PHP class API is `@internal` (#69).
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
    **Extended, not re-litigated, by the inlined `ApiError` throwable (issue #168):** a `final`
    exception, inlined into the consumer's own `\Support` namespace exactly like `RespondsWithStatus`,
    that carries any generated Data class (or other Responsable/Arrayable/JsonSerializable value) plus
    an HTTP status and self-renders through Laravel's `render(Request): Response` exception-handler
    hook (no `bootstrap/app.php` registration needed). It is a schema-agnostic CARRIER, not a
    renderer: it never inspects or maps a spec's error shape itself (that mapping is still the
    documented recipe's job), so this decision's core stance is unchanged. It solves a narrower,
    different problem than #79: the generated abstract controller method's return type is always the
    operation's success DTO (by design, error responses are never inspected for typing), so a concrete
    controller that must answer a spec-declared error status previously had to hand-roll a helper that
    RETURNS a JsonResponse, which does not satisfy the success return type. Throwing (never returning)
    satisfies any return type, so `ApiError::notFound($errorData)` and its sibling named-status
    factories give that throw an ergonomic, typed home without inventing a new generated renderer.
    **Further extended by the generated `<Operation>Errors` factory layer (issue #168):** the ApiError
    carrier is now complemented by a GENERATED per-operation factory, `<Operation>Errors` (one static
    method per concrete 4xx/5xx error response whose JSON schema resolves to a named component object; v1
    scope, inline-object, non-object, unresolvable, and default/wildcard error slots are documented
    residuals). An operation that DOES get a factory warns once per declared error slot it could not
    turn into a method; an operation with NO qualifying error slot generates no factory and stays
    silent (so specs whose error bodies are entirely non-objects or `default` catch-alls are not
    flooded with warnings).
    `throw GetPetByIdErrors::notFound(message: '...');` is now the PRIMARY, RECOMMENDED pattern for a
    spec-declared error whose operation has a generated factory; `ApiError`'s own named factories and
    general constructor remain a documented escape hatch for anything a generated factory does not
    cover (a cross-cutting error the spec does not declare per-operation, an operation whose error
    responses do not qualify for flattening, or a status the spec's per-operation responses map omits).
    Neither layer maps Laravel's error bag into a spec shape or inspects a spec's error schema on the
    developer's behalf beyond flattening an ALREADY-NAMED schema's own constructor; decision #11's core
    stance (no generated renderer) remains unchanged by either layer.
12. **Config diet: the generator is opinionated about style.** New options must be environment
    facts the generator cannot know (paths, middleware names, FQCNs) or correctness escape
    hatches, never style preferences. Style is the generator's job. The #93 (`--group-by-tag` /
    `output.group_by_tag`) and #94 (`--laravel-conventions` / `controllers.laravel_conventions`)
    flags were removed accordingly: the tag-grouped data layout and the Laravel-convention method
    names are the only behavior. #96 closed as not planned under the same principle.

## Current capabilities

### Fidelity report [shipped]
A machine-readable `openapi-laravel.unsupported.json` artifact emitted per run (default on),
listing every OpenAPI construct the generator could not faithfully represent AND that affects the
correctness or runtime behavior of the generated code: each entry carries an RFC 6901 `pointer`, a
human `location`, the `construct`, a one-line `impact`, and `severity: correctness`. Entries are
scanned from the raw document by `FidelityScanner` (Parser layer), deduped on (pointer, construct),
and sorted by pointer then construct, so the file is byte-stable across runs of the same spec. It
flows through the shared `GenerationPlanner` (CATEGORY_FIDELITY), so `openapi:check` drift-checks it
like every other artifact; an empty report still emits `"unsupported": []` for a stable presence.
Opt out with `output.unsupported_report: false` or `--no-unsupported-report` (force on with
`--unsupported-report`; both flags together is an exit-2 config error): the opt-out removes the file
from BOTH generation and the checked set, so a user who gitignores and deletes it never fails the
gate. The write path is config-only (`output.unsupported_report_path`, contained on the standalone
surface), defaulting to the project root (Laravel) or next to the output dir (standalone). After a
run with gaps, the commands print `N construct(s) not fully represented, see <path>`. Covered
constructs (current correctness gaps): repeated-key array query params, cookie params, content-typed
params, `allowEmptyValue`, matrix/label path params, success response headers, operation callbacks,
root webhooks, multipart `encoding`, undiscriminated object `oneOf`/`anyOf` (#31), `patternProperties`
value schemas, `$ref`-valued `additionalProperties` map values, the INTRACTABLE `not` forms (type
exclusion, nested shape, composition), and the 3.2 `query`/`additionalOperations`/`itemSchema` drops.

### int32/int64 range and the tractable `not` subset [shipped]
Two former fidelity gaps are now enforced, so they no longer appear in the report. An integer with
`format: int32` gets `min:-2147483648` / `max:2147483647`, and `format: int64` gets
`min:-9223372036854775808` / `max:9223372036854775807`, emitted as Laravel rule STRINGS (never PHP
int literals: the int64 minimum is PHP_INT_MIN and a literal overflows to float). An explicit
`minimum`/`maximum` (or a 3.1 numeric exclusive bound) on a side wins, so a format bound is added
only where the schema sets none, decided per side. The `not` keyword is partially supported: the
tractable `not: {enum: [...]}` and `not: {const: X}` forms emit `Rule::notIn([...])` (the object
form matching the project's `Rule::in` convention, so commas/quotes in values stay escaped); every
OTHER `not` shape (a bare type exclusion, a nested object schema, a composition, or a float/bool
const the literal helper cannot express) has no Laravel equivalent and stays recorded in the
fidelity report. The scanner records `not` only for those intractable forms.

### Models [shipped]
laravel-data classes, spec-derived `rules()` (exclusive bounds, `multipleOf`, `uniqueItems`, strict
date/date-time/time/duration, hostname, defaults, `const`), backed enums, a TRANSITIVE
readOnly/writeOnly split (a writable variant is synthesized when the schema OR any descendant
nested object, collection element, map value, allOf member, or union member declares a
readOnly/writeOnly property, and the write variant recurses into the nested and collection-nested
Data classes, so a client-sent value for a nested readOnly field is dropped on the write path at
any depth, including through a component `$ref`),
nested objects, collections, `allOf` merge, `additionalProperties` typed maps (empty map serializes
as `{}`), `oneOf`/`anyOf` native unions for scalars, and discriminated object unions (all three forms:
named-component, inline-union with synthesized variant names, and allOf-inheritance) validated and
hydrated via an abstract morphable base plus variants. Default-on closed-object enforcement that
scopes to its own attribute subtree, so a NESTED or collection-nested closed object enforces against
its own keys (keyed on the nested path) and a clean nested payload is not false-rejected (#30). The
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
`fromQuery(Request)` factory that validates and hydrates from the query string only. A non-exploded
delimited array query param (#132) is split in `fromQuery()` on its declared delimiter BEFORE the
array rules run (`style: form, explode: false` on comma, `spaceDelimited` on space, `pipeDelimited`
on pipe); the `form` + `explode: true` repeated-key default (`?tags=a&tags=b`) is unchanged and
stays a known limitation. A QueryData carrying ANY such delimited-array param is forced ADDITIVE (it
is NOT container-injected; the abstract method takes no injected query param and carries a
`::fromQuery($request)` docblock pointer, the same mechanism path (#113) and header (#121) params
use), because container injection would make spatie laravel-data validate the RAW unsplit string
before the split runs and 422 a body-less GET filter; a QueryData with no delimited-array param
stays container-injected on body-less ops as before. A `style: deepObject` OBJECT query param (#131,
Stripe's `?filter[gte]=10&filter[lte]=20`) is synthesized as a NESTED object property with dotted
nested rules (`filter.gte`, `filter.lte`, ...) through the same nested-object pipeline a body object
property uses; PHP parses the bracketed keys natively into a nested array, so no manual splitting is
needed and the param STAYS container-injectable on a body-less GET (a non-object deepObject schema,
or `deepObject` + `explode: false`, keeps the skip-and-warn). Path parameters
(#113) generate a per-operation path Data class (`<Operation>PathData`) the same way, with a
`fromRoute(Request)` factory validating and hydrating from `$request->route()->parameters()` only,
so a path segment's min/max/pattern/enum/format constraints are enforced at runtime (a bad value is
a 422, not a silent 200). It is the additive runtime-validation seam: the positional scalar path
arguments still fill the controller signature, and the abstract method carries a docblock pointer to
`::fromRoute($request)` rather than injecting the class. An `integer` path parameter additionally
carries a `->whereNumber('<token>')` route constraint: the abstract method types it `int`, so
under strict_types a non-numeric segment would otherwise bind to the typed `int` parameter and throw
an uncatchable TypeError 500 in Laravel's ControllerDispatcher BEFORE the controller body (and the
#113 `fromRoute()` guard) could run. The constraint makes a non-numeric segment fail to MATCH the
route, so the resulting status semantics are clean: a non-numeric integer-path segment is a 404
(route miss, before dispatch), and a numeric-but-out-of-range value still reaches the controller and
is a 422 through the #113 PathData guard. The constraint is keyed by the raw spec token (the wire
name Laravel matches), emitted in path order for byte-stable output; `number`/float path parameters
are typed `string` and stay unconstrained (no regression, no float matcher). Header parameters (#121) generate a
per-operation header Data class (`<Operation>HeaderData`) the same way, with a
`fromHeaders(Request)` factory validating and hydrating from the request headers only, so a
constrained custom header's min/max/pattern/enum/format is enforced at runtime instead of silently
dropped. Two header-specific wrinkles: HTTP header names are case-insensitive, so the wire key (the
`#[MapName]` and the rules() key) is the LOWERCASED spec name matching Symfony's
`$request->headers->all()`, and each header value is an array-of-strings, so the factory takes the
FIRST value of each header before validation; reserved/framework-owned standard headers (Accept,
Content-Type, Authorization, Host, User-Agent, Cookie, ...) are skipped with a warning so the
framework keeps managing them. Like the path class it is NOT injected: the abstract method carries a
docblock pointer to `::fromHeaders($request)`. The query, path, and header synthesis share one
location-parameterized core in RequestDataSynthesizer. An inline JSON
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
false-reject actual uploads). A request body that is a `$ref` to
`#/components/requestBodies/<Name>` (#110) resolves to the component and routes through the same
content-type logic: a wrapped schema `$ref` types the param with that component schema's existing
Data class (write variant when split), an inline object schema synthesizes ONE shared
`<Component>RequestData` class reused by every referencing operation (placed in the single tag
group they share, or the flat root when they span groups), and a non-object shape keeps the warned
Request fallback. An `application/x-www-form-urlencoded` OBJECT body (#130) routes through the SAME
`<Operation>RequestData` synthesizer as inline JSON (Laravel parses urlencoded input into
`$request->all()` exactly like JSON, validated by the same spec-derived rules), for inline,
schema-`$ref`, and component-`$ref` bodies; media-type precedence is JSON > multipart >
form-urlencoded (JSON always wins when several are declared), and a non-object urlencoded body keeps
the warned Request fallback. A selected success response that is a `$ref` to `#/components/responses/<Name>`
(#116) resolves the same way on the output side: a wrapped schema `$ref` types the return with that
component schema's existing Data class, an inline object schema synthesizes ONE shared
`<Component>ResponseData` class (READ variant: responses are server output, so readOnly fields stay
and writeOnly fields drop) with the same tag-group placement rule, the #64 status semantics are
preserved unchanged (smallest 2xx, `RespondsWithStatus` middleware, a 204 `$ref` stays `void`
without any resolution attempt), and a non-object shape keeps the warned JsonResponse fallback. An
INLINE (non-`$ref`) 2xx object response schema (#129) synthesizes a per-operation
`<Operation>ResponseData` class typed as the controller return (READ variant: readOnly stays,
writeOnly drops), symmetric with the inline request body (#76); the #64 status semantics are
preserved (an inline 204 stays `void`, a non-200 success keeps its status middleware), and an inline
NON-object success response (array, scalar, oneOf/anyOf union, enum, free-form map) keeps the warned
JsonResponse fallback. Hybrid
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
- A discriminator that is UNKNOWN (#124) OR MISSING ENTIRELY (#126) is a clean 422 on every
  consumption path. #124 made the morphable base's `morph()` default arm throw a `ValidationException`
  rather than returning null, so an unmapped value rejects before the uncatchable
  `CannotCreateAbstractClass` (a 500) can fire on the creation paths (`from()` /
  `validateAndCreate()` / container injection). #126 closes the missing-key sibling: spatie's
  `DataMorphClassResolver` short-circuits to a null morph (which the creation paths turn into the
  same 500) when the morphable property is ABSENT and has no default, so the base now declares the
  discriminator NULLABLE with a `null` default. The resolver then calls `morph()` with `null` for a
  missing key, the default arm throws, and a missing discriminator is a clean 422 on `from()` /
  `validateAndCreate()` / container injection too (the `validate()` path already rejected it). `null`
  is the sentinel because no discriminator mapping value is ever null (true for an int discriminator
  too, where a non-null literal default could be a real mapping key); the `Required` attribute keeps
  the spec-required contract, and the variants are untouched (they forward a non-null value into the
  nullable parameter, so a valid payload hydrates the right variant with its real value).
- Object request bodies resolve to typed Data params across every form, inline JSON (#76), multipart
  (#75), form-urlencoded (#130), and component `$ref` (#110); only a body that is NOT an object shape
  (an array, scalar, union, enum, or free-form map, in any media type) or a whole-body raw binary
  (octet-stream, #119) keeps the warned `Illuminate\Http\Request` fallback. Responses resolve to
  typed returns the same way: inline (#129) and component `$ref` (#116) object response schemas both
  synthesize a typed return; an inline NON-object success response (array, scalar, oneOf/anyOf union,
  enum, free-form map) keeps the warned JsonResponse fallback, a component response whose JSON schema
  is NOT an object shape keeps it too, and an unresolvable response `$ref` (external, `#/paths/...`,
  missing, or ref-to-ref) keeps it as well.
- Multipart residuals (#75): no file-size rule is derived (OpenAPI has no standard byte-size
  keyword; `maxLength` is a string bound and Laravel's file `max:` counts kilobytes, no clean
  mapping), the `encoding.contentType` map is not read (only schema-level `contentMediaType` feeds
  `mimetypes:`), and a binary string nested BELOW the body root stays a plain string (it sits in a
  JSON-serialized part).
- Tuple `prefixItems` validates per position (#82: `field.0`, `field.1`, ... rules through the
  shared constraint mapping, plus a `max:` length cap for the closed `items: false` form) but the
  TYPING still degrades to `array<int, mixed>`; a post-prefix `items` schema stays unenforced (a
  `field.*` rule would false-reject the prefix positions).
- int64/bignum values are typed `int` in the docblock and not promoted to an arbitrary-precision
  type (a value beyond PHP_INT_MAX cannot round-trip), though the int64 range is now ENFORCED as a
  validation rule (see the int32/int64 section above); `$ref`-valued map values degrade gracefully
  (typed in the docblock, not auto-hydrated). A non-JSON-only success response (text/html, binary downloads) is
  typed as the base Symfony `Response` with a warning (#117/#118): honest typing (any Response
  subclass satisfies it), but no schema-derived validation or Data return exists for it. A response
  with NO declared content keeps the JsonResponse default (the spec says nothing about the body).
- A spec `pattern` is copied verbatim into the generated `regex:` rule with no complexity analysis
  (#107), so a catastrophic-backtracking pattern in the spec becomes a potential ReDoS at runtime in
  the generated app. PHP's `pcre.backtrack_limit` is the only backstop, turning a hang into a failed
  match rather than preventing the CPU burn.
- `in: header` parameters are now validated (#121): a per-operation `<Operation>HeaderData` class
  with spec-derived rules() and a `fromHeaders(Request)` factory enforces a constrained custom
  header at runtime (a bad value is a 422, not a silent 200). The wire key is the LOWERCASED spec
  name (HTTP headers are case-insensitive; Symfony lowercases them) and the factory takes the first
  value of each header's array. Reserved/framework-owned standard headers (Accept, Content-Type,
  Authorization, Host, ...) are skipped with a warning, and a non-scalar/non-enum header schema
  degrades to a warned presence-only `mixed`. `in: cookie` parameters are still not generated
  (warned per operation, deliberately out of scope).
- Response headers are not generated (warned per operation, #114). Only the SELECTED success
  response warns: it is the one response the generator consumes, so headers on error responses (or
  bypassed success alternatives) stay silent by design.
- Operation `callbacks` (warned per operation) and root `webhooks` (one document-level warning)
  are not generated (#115); callback/webhook handler scaffolding is a separate decision,
  deliberately out of scope for now.
- The `form` + `explode: true` repeated-key query array (`?tags=a&tags=b`, the OpenAPI default) is a
  known limitation: PHP collapses repeated query keys, so only the last value survives. Non-exploded
  delimited arrays (#132, `form`+`explode: false`, `spaceDelimited`, `pipeDelimited`) ARE split in
  `fromQuery()`, and `deepObject` OBJECT params (#131) ARE synthesized as nested object properties; a
  non-object deepObject schema, a `deepObject` + `explode: false`, an object-shaped (non-deepObject)
  param, and a content-typed param are still skipped with a warning.
- Array query parameters validate their elements against the spec type but hydrate them as the raw
  query strings (PHP query parsing produces strings; top-level scalars hydrate typed).
- Only the SELECTED success response (smallest 2xx) is typed and status-enforced (#64); alternative
  declared 2xx statuses on the same operation stay the implementer's responsibility (the middleware
  never overrides an explicitly set non-200 status).
- A POST whose selected success response is exactly 200 and whose return type serializes through
  spatie (a Data class, a DataCollection, a union of Data classes) is the one gap the #64 middleware
  gate leaves: `ResponsableData::calculateResponseStatus()` derives the status from the HTTP VERB, so
  that operation answers 201 Created while the contract says 200, and an exactly-200 declaration
  attaches no middleware to reconcile them. Resolved as a DIAGNOSTIC, not a behavior change (#174,
  the issue's option 1): ONE document-level warning per run listing every affected operation (the
  `Document webhook(s)` shape, deliberately not one warning per operation: the predicate matches 193
  operations in plaid.json and 288 in google_compute.json, and repeating a 300-character paragraph
  that often would bury the scarcer security-middleware and media-type diagnostics that share the
  same sorted channel). Attaching the middleware automatically would work, but it would have the
  generator pick a side in a REST argument (a create arguably SHOULD be 201) and override a
  defensible framework default, so it warns instead. PUT/PATCH/DELETE answer 200 through spatie and
  match their declaration; a `void`, `JsonResponse`, or base `Response` return is silent because the
  implementer sets the status there.
  The prescribed fix is to declare 201, removing the 200 when both are declared (the smallest 2xx
  wins, so the method is otherwise typed against the 200's schema while the runtime sends the 201,
  whose schema may differ). Keeping the 200 is possible but is NOT a routes-file edit (that file is
  drift-checked) and NOT the `routes.middleware` key (it wraps the WHOLE table in one group, so a
  `RespondsWithStatus:200` there would sit outside the per-route middleware and rewrite genuine 201
  and 204 responses back to 200); the regeneration-safe seam is controller middleware via
  `controllers.base_class` + Laravel's `HasMiddleware` scoped with `only:`, or `--no-routes`. The
  handler cannot set the status either: the abstract method's return type IS the generated Data
  class and those are `final`, so the signature cannot widen to a JsonResponse and
  `calculateResponseStatus()` cannot be overridden. `RespondsWithStatus` is inlined only when some
  operation declares a non-200 status, so an all-200 spec never receives that class at all.

### Client generation [maybe, decide later]
Generate a typed PHP client for *consuming* a third-party or internal API from its spec, built on the
`Http::` facade. Not a near-term commitment.

## Toward 1.0.0

The enforcement and correctness layers are in place: full output by default, drift as a CI gate, the
differential oracle guarding every emitted constraint, self-contained output (no runtime coupling),
and a clean large-corpus sweep. The remaining gate to 1.0.0 is **output-format stability**: hold the
format steady across a few releases, then tag 1.0.0 as the output-stability promise.

The four 1.0.0-readiness docs are finalized and live under `docs/src/content/docs/guides/`:
supported OpenAPI versions (`openapi-versions.mdx`), versioning and upgrades (`versioning-policy.mdx`),
runtime coupling (`runtime-coupling.mdx`), and the fidelity report (`unsupported-report.mdx`).

Remaining smaller follow-ups (not blockers):
- Richer error messages on bad input.
- Cross-repo: the openapi-zod-ts `Accept: application/json` gap (openapi-zod-ts #289), surfaced by the
  e2e demo. The fix lives in the sibling repo, not here.
- Fidelity report backlog: issues #138-143 track planned coverage expansions for the report
  (additional recorded constructs, machine-readable severity tiers, and consumer-facing tooling).

**What 1.0.0 means:** at the cutover, flip `enforce_admins: true`, require 1 PR review, set required
checks `strict: true`, and move from the direct-push flow to a PR + review workflow.

## Open questions (genuine design decisions)

- **Map-of-`$ref` value hydration**: a `$ref`-valued `additionalProperties` map is typed in the
  docblock but not auto-hydrated into Data objects at runtime.
- **Exact laravel-data v4 feature surface** for casts/transformers (dates, enums, nested and
  typed-map value hydration).
- **Client generation**: whether to build the consuming-client direction at all (see above).

## Security posture

The spec is untrusted input and the generated PHP is loaded and executed by the host. Defenses:
docblock-injection neutralization, an identifier whitelist (path traversal, identifier injection, the
`this` fatal), escaped literals, validated namespace options, structural rejection of non-OpenAPI
documents, an input-size guard and a total hydrated-node-count guard against YAML alias amplification
(#107), operator-controlled CLI output paths, config-file output-path containment (#54), and a hostile-input
regression suite. Config containment: the standalone binary auto-discovers `openapi-laravel.json` from
the working directory, so every config-sourced write path (`output.path`, `controllers.path`,
`routes.path`) is contained by `PathContainment` to the config's directory; a `..` traversal, an
absolute escape, or a symlinked-parent escape fails closed before any write. CLI flags keep full
freedom by design. YAML alias amplification (a sub-kilobyte anchor/alias bomb that sails under the
byte and depth guards but fans out to millions of schema nodes) is bounded by a total
hydrated-node-count guard in `OpenApiReader` (#107, `DEFAULT_MAX_NODES`, far above the largest corpus
spec), which fails closed with a clear `ParseException` before memory is exhausted.

## Repo and release governance

- **Now (pre-1.0.0):** direct commits to `main`, push on maintainer approval. Branch protection blocks
  force-push and deletion, restricts push to the maintainer, and requires CI status checks;
  `enforce_admins` is off and PR reviews are not required, so the direct-push flow works.
- **At 1.0.0:** flip `enforce_admins: true`, require 1 PR review, set required checks `strict: true`,
  move to a PR + review workflow.

## Test strategy

Pest unit tests per feature (a minimal in-memory spec builder) plus snapshots. Corpus: all 135
fixtures parse and generate valid PHP, passing the import-resolution gate (model and server gates),
the `php -l` compile gate, and the allOf/additionalProperties/oneOf non-empty guards. The 5
OpenAPI 3.2 fixtures (#104 T8: the official Learn OpenAPI QUERY example, the Redocly Museum API
upgraded to 3.2, and three per-construct fixtures) additionally prove the #103 best-effort path
end-to-end in `OpenApi32CorpusTest`: the loud 3.2 warning, per-construct dropped warnings, typed
stub hydration (query, additionalOperations, defaultMapping, itemSchema), and compilable generated
output. A synthetic
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
