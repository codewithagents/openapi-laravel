# openapi-laravel

[![CI](https://github.com/codewithagents/openapi-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/codewithagents/openapi-laravel/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/codewithagents/openapi-laravel)](https://packagist.org/packages/codewithagents/openapi-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/codewithagents/openapi-laravel)](https://packagist.org/packages/codewithagents/openapi-laravel)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](./LICENSE)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg)](https://php.net)

> One OpenAPI spec in, a working typed Laravel slice out: laravel-data models, spec-derived validation, native enums, controllers, and routes, out of the box, drift-gated in CI. The spec is the source of truth, your code follows it.

**Documentation: [openapi-laravel.codewithagents.de](https://openapi-laravel.codewithagents.de)**

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://openapi-laravel.codewithagents.de/media/infographic-dark.svg">
  <img alt="From OpenAPI spec to generated Data classes, validation rules and controllers; you write only business logic; openapi:check fails CI on drift and regeneration never touches your files" src="https://openapi-laravel.codewithagents.de/media/infographic-light.svg" width="880">
</picture>

Hand-written DTOs, validation rules, and controllers drift from the API contract silently. Nobody
notices the missing `nullable`, the renamed wire field, or the new enum case until production rejects
a valid payload, or a consumer files the bug for you. The spec already states every one of those
shapes; the drift exists only because humans re-type them.

So make the spec the source of truth and regeneration the sync mechanism. One command emits
[spatie/laravel-data](https://github.com/spatie/laravel-data) classes with explicit, spec-derived
`rules()` methods plus native PHP enums, an abstract controller per tag, and a routes file, so the
request/response types and the routing table derive from the spec too. When the spec changes, you
re-run the generator and review the diff.

Unlike annotation-driven tools that generate a *spec from your code*, this goes the other way: the
spec drives the code. And unlike most generators, the output is not a black box. It is readable,
deterministic PHP that lives in your repo and looks like code you would have written yourself.

```php
// generated from components.schemas.Customer
final class CustomerData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        #[MapName('email_address')]
        public readonly ?string $emailAddress = null,
        public readonly ?CustomerStatus $status = null,
    ) {}

    public static function rules(): array
    {
        return [
            'id'            => ['required', 'integer', 'min:1'],
            'name'          => ['required', 'string', 'max:255'],
            'email_address' => ['sometimes', 'nullable', 'string', 'email'],
            'status'        => ['sometimes', Rule::enum(CustomerStatus::class)],
        ];
    }
}
```

Sibling project of [openapi-zod-ts](https://github.com/codewithagents/openapi-zod-ts), which does the
same for TypeScript. Both are tested against a shared corpus of real-world public API documents,
130 specs on this side (detailed in [Why quality matters](#why-quality-matters) below).

The generated classes extend `Spatie\LaravelData\Data`, so
[spatie/laravel-data](https://github.com/spatie/laravel-data) v4 is a **runtime peer dependency of
your app** (a normal `require`, not `require --dev`). The generator itself is a dev dependency.

---

## Install

The generator is a dev dependency; `spatie/laravel-data` is a runtime dependency of your app because
the generated classes extend it:

```bash
composer require spatie/laravel-data:^4.15
composer require --dev codewithagents/openapi-laravel
```

Publish the config (optional):

```bash
php artisan vendor:publish --tag=openapi-laravel-config
```

## Quick start

Point it at your spec and generate:

```bash
php artisan openapi:generate --spec=openapi.yaml --output=app/Data
```

One command emits the full output: Data classes with `rules()`, native enums, one abstract
controller per tag (`app/Http/Controllers/Api` by default), and a `routes/api.generated.php`
file, all typed against each other. Then scaffold the concrete controllers the routes file
references, one time, so the app boots immediately:

```bash
php artisan openapi:scaffold
```

Each stub extends its generated abstract controller and implements every operation as an explicit
`throw new LogicException('Not implemented: ...')` placeholder. Existing files are skipped, never
overwritten: the stubs are your code from the moment they are written, and `openapi:check` never
inspects them. Models only? Opt out per run:

```bash
php artisan openapi:generate --no-controllers --no-routes
```

The artisan command writes into the namespace from `config/openapi-laravel.php` (`output.namespace`,
default `App\Data`), overridable per run with `--namespace`. Set `spec` and `output.path` in the
config too and you can then just run `php artisan openapi:generate`. Settings resolve with strict
precedence: flags beat the config, the config beats the built-in defaults (`controllers.enabled` and
`routes.enabled` can disable the scaffold permanently; `--controllers` / `--routes` force it back on
for one run).

Not a Laravel project? The same generator ships as a framework-free binary. It reads an optional
`openapi-laravel.json` from the working directory (or `--config=<path>`) whose keys mirror the
Laravel config, with the same flag-over-config precedence. Controllers and routes default to
`<output>/Controllers` and `<output>/routes.php`:

```bash
vendor/bin/openapi-laravel --spec=openapi.yaml --output=src/Data --namespace="Acme\\Dto"
```

![Full flow: generate models, rules, controllers and routes from an OpenAPI spec, catch drift in CI, regenerate without touching hand-written code](https://openapi-laravel.codewithagents.de/media/openapi-laravel-full-flow.gif)

*One spec drives models, validation rules, controllers and routes; the drift gate fails CI when they disagree, and regeneration never touches your code.*

*The same flow powers the [cross-language e2e demo](./e2e): one spec, a generated Laravel backend, and a TypeScript SPA.*

### Keep generated code in sync (CI)

Once the generated files are committed, add `openapi:check` to your CI pipeline. It regenerates
the full file set in memory and compares it byte-for-byte against what is on disk, without writing
anything:

```bash
php artisan openapi:check
# or, without Laravel:
vendor/bin/openapi-laravel check
```

Exit codes: `0` the committed files match the spec, `1` drift detected (the build fails), `2` a
config or spec error. Add `--diff` to print a bounded unified diff per changed file so you can see
exactly what drifted. The check honors the same flags as `openapi:generate` (`--spec`, `--output`,
`--namespace`, `--no-controllers`, `--no-routes`, `--laravel-conventions`) and only compares
generator-owned files, so hand-written concrete controllers are never flagged as drift.

![openapi:check failing the build on contract drift](https://openapi-laravel.codewithagents.de/media/openapi-laravel-drift-check.gif)

See the [drift-check guide](https://openapi-laravel.codewithagents.de/guides/drift-check) for a
full CI walkthrough.

---

## Pipeline

```
openapi.yaml
  └── openapi-laravel  →  app/Data/CustomerData.php          (laravel-data class + rules())
                          app/Data/CustomerStatus.php        (native backed enum)
                          app/Data/CustomerWritableData.php  (write variant, when the spec
                                                              uses readOnly/writeOnly)
                          app/Http/Controllers/Api/AbstractCustomerController.php
                          routes/api.generated.php
```

You write your business logic. The DTOs, their validation, the controller signatures, and the
routing table stay in sync when the spec changes.

What the generator handles today:

- **Objects** → `laravel-data` classes with promoted, readonly constructor properties
- **Validation** → explicit `rules()` derived from the spec: `required`/`nullable`, types, string
  `max`/`min`/`pattern`/`format` (email, uuid, url, ip, hostname, time, duration), numeric
  `min`/`max`, **exclusive bounds** (`gt`/`lt` for `exclusiveMinimum`/`exclusiveMaximum`, both the
  3.0 boolean and 3.1 numeric forms), **`multipleOf`**, array `min`/`max` items and
  **`uniqueItems`** (`distinct`), **`dependentRequired`** (`required_with` on the dependent,
  `present_with` when nullable), and `Rule::enum` / `Rule::in`
- **Dates** → strict RFC3339 for `format: date-time` (accepts Z/offset/fractional, rejects a bare
  date) and `date_format:Y-m-d` for `format: date`, the two kept distinct
- **Defaults** → a scalar `default` seeds the constructor parameter and makes the property optional
  (`sometimes`) on input
- **Enums** → native PHP backed enums (string or int); a `number`/`float` enum (which cannot be a
  backed enum) emits `Rule::in` over its values
- **Naming** → StudlyCaps classes, camelCase properties, `#[MapName]` when the wire name differs,
  reserved-word and collision handling
- **Nested objects** → nested Data classes; **arrays** → `#[DataCollectionOf]` typed collections
- **Nullability** → both 3.0 `nullable` and 3.1 `type: [..., 'null']`
- **readOnly / writeOnly** → a read variant and a write variant, only when the spec uses the flags
- **`allOf`** → merged into one flat class, members unioned, `required` deduped, conflicts resolved
  deterministically
- **`additionalProperties`** → typed maps (`array<string, X>`) with per-value wildcard rules; a
  pure-map component is inlined at its use sites instead of emitting an empty class, and an empty map
  serializes as `{}` (not `[]`). `additionalProperties: false` is enforced by default (the spec is the
  source of truth), rejecting undeclared keys; `--no-enforce-closed-objects` opts out for lenient,
  forward-compatible output during contract evolution
- **`oneOf` / `anyOf`** → a **scalar** union emits a native PHP union type plus a variant docblock
  (`string|int`). A **discriminated object union** (a named `oneOf`/`anyOf` of object `$ref`s with a
  `discriminator`) emits an abstract morphable base plus one variant class per member: the payload is
  validated against the selected variant's rules and hydrated polymorphically at runtime (issue #38).
  An **undiscriminated** object union is typed `mixed` and validated for presence only, with the
  variant union kept in the `@var` docblock (`/** @var CatData|DogData */`), so every valid variant
  is accepted and no valid payload is false-rejected (issue #31). Messier members fall back
  deterministically to `mixed`. An array whose `items` are a union emits a plain typed
  `array<int, A|B>` with a docblock, not `#[DataCollectionOf(A|B::class)]`: that attribute is
  syntactically accepted by PHP but resolves wrongly at runtime due to operator precedence (bug #24,
  fixed in 0.6.0)
- **Multi-type scalars** → `type: ["string","integer"]` becomes a `string|int` union (`["x","null"]`
  stays a nullable scalar)
- **Non-object components** → a top-level component that is itself a scalar, an array, or a
  `oneOf`/`anyOf` union is aliased to its underlying type at every `$ref` use site, instead of an
  empty Data class that would silently fail to hydrate
- **`deprecated`** → `@deprecated` docblocks on generated classes and properties (with an optional
  reason from the `x-deprecated-reason` vendor extension), so IDEs strike through and PHPStan warns
- **Server scaffold** (generated by default, opt out with `--no-controllers` / `--no-routes`) → an
  abstract controller per tag and a routes file, typed by the Data classes, so an unimplemented
  operation is a PHP fatal and path-level drift is structurally impossible. Opt in to
  Laravel-convention method and route names (`index`/`show`/`store`/`update`/`destroy` for clean
  RESTful operations, deterministic fallback to the operationId-derived name for everything else)
  with `--laravel-conventions`
- **Query parameters** → a per-operation query Data class with spec-derived `rules()` (enums, bounds,
  array element rules, defaults). Type-hinted into body-less controller methods (validated on
  injection); operations with a request body call `<Operation>QueryData::fromQuery($request)`, which
  validates and hydrates from the query string only. Header/cookie and deepObject-style parameters
  are skipped with a generator warning, not silently dropped
- **Spec response status codes** → an operation whose success response declares a non-200 status
  (201, 202, 204, ...) produces that status out of the box: the generated route attaches an inlined
  `RespondsWithStatus` middleware, your controller keeps returning the plain Data object, and a 204
  operation is typed `void` and answers with an empty body
- **Determinism** → same spec in, byte-identical files out

The same spec also produces a typed abstract controller per tag and a routes file. An operation you
forget to implement is a PHP fatal at class-definition time, not a gap discovered in production:

```php
// generated: app/Http/Controllers/Api/AbstractPetController.php
// (this Pet schema marks some fields readOnly/writeOnly, so the request type is
//  the write variant PetWritableData and the response type is the read variant
//  PetData; a schema with no such flags would use a single PetData both ways)
abstract class AbstractPetController
{
    abstract public function addPet(PetWritableData $pet): PetData;
    abstract public function getPetById(int $petId): PetData;
    abstract public function deletePet(int $petId): void;   // the spec declares 204: nothing to return
}

// generated: routes/api.generated.php
// (the spec declares 201 for addPet and 204 for deletePet, so those routes
//  carry the inlined status middleware; plain 200 operations stay untouched)
Route::post('/pet', [PetController::class, 'addPet'])->name('addPet')->middleware(RespondsWithStatus::class.':201');
Route::get('/pet/{petId}', [PetController::class, 'getPetById'])->name('getPetById');
Route::delete('/pet/{petId}', [PetController::class, 'deletePet'])->name('deletePet')->middleware(RespondsWithStatus::class.':204');
```

You write only the concrete `PetController extends AbstractPetController`, and
`php artisan openapi:scaffold` (or `vendor/bin/openapi-laravel scaffold`) writes its initial,
one-time stub for you. See the
[server scaffold guide](https://openapi-laravel.codewithagents.de/guides/server-scaffold) for the
full walkthrough.

A few OpenAPI features degrade gracefully rather than crash. An **undiscriminated** object union
(`oneOf` of Data classes with no `discriminator`) is typed `mixed` and validated for presence only:
every valid variant is accepted (no valid payload is false-rejected, issue #31), but the variant is
neither enforced nor auto-hydrated. Add a `discriminator` to the spec and the generator emits the
morphable base and variants, with full per-variant validation and hydration in all three
discriminator forms: named-component, inline-union, and allOf-inheritance (issue #38). A
`$ref`-valued `additionalProperties` map is typed in the docblock but not auto-hydrated into Data
objects at runtime, a request body referencing a component `$ref` falls back to
`Illuminate\Http\Request` instead of a typed Data param, and int64 literal bounds and non-JSON
responses are represented loosely. A tuple (`prefixItems`) is validated per position (`field.0`,
`field.1`, ... rules, plus a length cap for the closed `items: false` form) but typed loosely as
`array<int, mixed>`. A non-standard per-property `required: true` key (a boolean set inside an
individual property schema, which some generators emit) is ignored, because OpenAPI 3.x only honours
the schema-level `required: [...]` array; the field is generated as optional and the run prints a
diagnostic to stderr naming the property and schema, so use the schema-level array to make a field
required. See the
[limitations guide](https://openapi-laravel.codewithagents.de/guides/limitations) for the full,
honest list.

---

## The hard case

The Customer example above is the easy 80%. The schema below is the kind that breaks generators: the
`Pet` schema from the e2e demo spec ([`e2e/spec/petstore.yaml`](./e2e/spec/petstore.yaml)) combines a
nested `$ref`, a typed collection, an inline enum, a nullable number, an `additionalProperties` map,
a scalar `oneOf` union, a snake_case wire name, and a readOnly/writeOnly split, in one object.
Trimmed to the interesting parts:

```yaml
Pet:
  type: object
  required: [name, photoUrls]
  properties:
    category:
      $ref: '#/components/schemas/Category'        # nested $ref
    tags:
      type: array
      items:
        $ref: '#/components/schemas/Tag'           # typed collection
    status:
      type: string
      enum: [available, pending, sold]             # inline enum
    microchip_id:
      type: string                                 # snake_case wire name
    secret_note:
      type: string
      writeOnly: true                              # write shape only
    created_at:
      type: string
      format: date-time
      readOnly: true                               # read shape only
    weight_kg:
      type: number
      nullable: true                               # nullable number
    attributes:
      type: object
      additionalProperties:
        type: string                               # string-to-string map
    external_id:
      oneOf:                                       # scalar union
        - type: string
        - type: integer
```

The generator turns that into two classes, because the readOnly/writeOnly flags mean the read shape
and the write shape differ on the wire. The read variant
([`e2e/backend/app/Data/PetData.php`](./e2e/backend/app/Data/PetData.php), trimmed):

```php
final class PetData extends Data
{
    public function __construct(
        public readonly string $name,
        /** @var array<int, string> */
        public readonly array $photoUrls,
        public readonly ?int $id = null,
        public readonly ?CategoryData $category = null,        // nested $ref
        /** @var array<int, TagData> */
        #[DataCollectionOf(TagData::class)]
        public readonly ?array $tags = null,                   // typed collection
        public readonly ?string $status = null,
        #[MapName('microchip_id')]
        public readonly ?string $microchipId = null,           // wire name mapping
        #[MapName('created_at')]
        public readonly ?string $createdAt = null,             // readOnly: read shape only
        #[MapName('weight_kg')]
        public readonly ?float $weightKg = null,               // nullable number
        /** @var array<string, string> */
        public readonly ?array $attributes = null,             // additionalProperties map
        /** @var string|int */
        #[MapName('external_id')]
        public readonly string|int|null $externalId = null,    // scalar oneOf union
    ) {}

    public static function rules(): array
    {
        return [
            'name'         => ['required', 'string'],
            'photoUrls'    => ['required', 'array'],
            'photoUrls.*'  => ['string'],
            'status'       => ['sometimes', Rule::in(['available', 'pending', 'sold'])],
            'attributes'   => ['sometimes', 'array'],
            'attributes.*' => ['string'],
            // ... trimmed
        ];
    }
}
```

The write variant ([`e2e/backend/app/Data/PetWritableData.php`](./e2e/backend/app/Data/PetWritableData.php))
is the mirror image: it carries `secret_note` (writeOnly, accepted on create, never read back) and
drops `created_at` (readOnly, set server-side). The abstract controller types
`addPet(PetWritableData $pet): PetData`, so the split is enforced at the signature level, not by
convention.

This exact schema is exercised by the Playwright e2e suite over real HTTP: the `#[MapName]` field
round-trips in both directions, the writeOnly secret never appears in a response, `null` stays
`null`, the map round-trips intact, and the scalar union arrives uncoerced.

---

## Philosophy

**The spec is the source of truth.** Code follows the contract, never the other way around. This is
the opposite of annotation-driven tools where your PHP generates the spec.

**You own the output.** Generated classes are readable PHP in your repo. Review them, commit them,
read them in a diff. No opaque runtime, no reflection magic you can't follow.

**Explicit over inferred.** Validation rules are emitted verbatim from spec constraints, not guessed
from property types at runtime. What the contract says is what gets validated.

**Deterministic.** Stable ordering everywhere. Regenerating produces a byte-identical diff or no diff
at all, so the generator is safe to run in CI and commit.

**Modern only.** PHP 8.2+, Laravel 11/12/13, laravel-data v4. No legacy shims.

---

## How it compares

The Laravel ecosystem is full of **code-first** tools that generate an OpenAPI document *from* your
controllers and annotations: [l5-swagger](https://github.com/DarkaOnLine/L5-Swagger),
[dedoc/scramble](https://scramble.dedoc.co/), [vyuldashev/laravel-openapi](https://github.com/vyuldashev/laravel-openapi).
Those are excellent if your code is the source of truth. This tool is for the other direction:
**spec-first**, where the OpenAPI document is the contract and your models derive from it.

| | `openapi-laravel` | l5-swagger / scramble | ensi-platform/* | hand-writing |
|---|---|---|---|---|
| Direction | **Spec → code** | Code → spec | Spec → code | n/a |
| Generates laravel-data DTOs | **Yes** | No | No (custom DTOs) | You do |
| Spec-derived validation `rules()` | **Yes**, differentially tested against the spec | No | Partial | You do |
| Native PHP enums | **Yes** | No | No | You do |
| Server scaffold (abstract controllers + routes) | **Yes** (default) | No | Yes | You do |
| `allOf` / `additionalProperties` | **Yes** | n/a | Partial | You do |
| `oneOf` / `anyOf` | **Scalar union type hints**; discriminated object unions validated and hydrated; undiscriminated ones presence-only, no false-reject | n/a | Partial | You do |
| Minimum Laravel version | **11** | 9+ | 10+ | n/a |
| Runtime peer dependency | `spatie/laravel-data` v4 | none | own DTO layer | none |
| Standard OpenAPI (no custom extensions) | **Yes** | Yes | No (custom OAS) | n/a |
| Owned, readable, committed output | **Yes** | n/a | Generated | Yes |
| Runs without Laravel (CI) | **Yes** (bin) | No | No | n/a |
| Drift detection in CI | **Yes** (`openapi:check`, byte-level, exit 1 on drift in generator-owned files) | n/a (spec is generated from code) | No | Manual review |

The `spatie/laravel-data` v4 runtime peer is a real adoption cost: the generated DTOs are
laravel-data classes, so your app takes on that dependency and its conventions.

**Pick something else if:**

- your code is the source of truth and you want the spec generated from it (l5-swagger, scramble);
- you are on Laravel 10 or older, or your app standardizes on a DTO/validation layer other than
  `spatie/laravel-data` v4;
- you need a non-PHP target (the sibling
  [openapi-zod-ts](https://github.com/codewithagents/openapi-zod-ts) covers TypeScript).

---

## Why not just ask an AI agent?

An agent can write a Data class from your spec, and the first one will probably be fine. But the
output is non-deterministic (the same prompt produces a different class tomorrow), nobody reviews the
hundredth one, and the moment the spec changes you are back to hand-maintained code that drifts. This
generator is deterministic: same spec in, byte-identical files out. That makes the output diffable,
reviewable once instead of every time, and re-runnable in CI on every spec change.

The honest kicker: agents built this generator. The generator is what makes their output trustworthy
on the hundredth run, not just the first.

---

## Proof: a full contract-first round trip

The strongest claim a generator can make is that its output actually interoperates over the wire. The
[`e2e/`](./e2e) directory proves exactly that from a single spec, and the full cross-language loop is
green: one spec drives a generated Laravel backend and a generated TypeScript client and SPA, and a
Playwright headless-Chrome suite drives the browser through the whole stack over real HTTP.

```
  e2e/spec/petstore.yaml          (one OpenAPI document, the source of truth)
        │
        ├── openapi-laravel  →  a real Laravel 12 backend
        │                       (Data classes + abstract controllers + routes)
        │
        └── openapi-zod-ts   →  a typed TypeScript client  →  a SPA
                                                                │
            Playwright headless-Chrome E2E, over real HTTP:
            browser → SPA → generated client → Laravel backend
```

One spec, two languages, no hand-written types on either side of the wire. Run the whole thing
yourself (you need Docker Desktop and Node.js 18+); the runner brings the stack up, runs the
headless-Chrome suite, and tears it down:

```bash
cd e2e/e2e-tests && npm install && ./run.sh
```

The suite proves the cross-language serialization seams round-trip over real HTTP: a `snake_case`
wire field forcing `#[MapName]` (mapped both directions), a `writeOnly` field accepted on write and
never read back, a `readOnly` `date-time` set server-side and ignored on input, a `nullable` number
where `null` stays `null`, an `additionalProperties` map that round-trips intact, and a
`oneOf: [string, integer]` scalar union with no coercion. A valid create returns `201`, an invalid
one returns `422` from the spec-derived `rules()` surfaced in the browser, and a delete returns `204`.

Running two independent generators against one contract surfaced two honest findings, both since
addressed: an empty `additionalProperties` map serialized as `[]` rather than `{}` (the classic PHP
empty-array ambiguity), now fixed in 0.5.0 so empty maps serialize as `{}`; and the generated
openapi-zod-ts client omits the `Accept: application/json` header, which broke browser
content-negotiation against Laravel until a small middleware was added in the demo backend (filed
upstream as [openapi-zod-ts #289](https://github.com/codewithagents/openapi-zod-ts/issues/289)).

Use it two ways: as **proof** that both generated sides agree on the wire, and as a **template** a
team can copy to bootstrap a spec-first project. See [`e2e/`](./e2e) for the full reference.

---

## Security: the spec is untrusted input

The generator reads an OpenAPI document and writes PHP that your application then loads and executes,
so it treats the spec as untrusted input. Docblock injection is neutralized, namespace and class-name
options are validated before any file is written, validation patterns are never silently dropped,
non-OpenAPI documents are rejected with a clear error, and a pre-parse input-size guard caps the YAML
alias-bomb blast radius. A hostile-input regression suite guards all of these. Output paths are
written exactly where you point them by design, so point them at fixed, operator-controlled locations
and never derive them from untrusted input. See the
[limitations guide](https://openapi-laravel.codewithagents.de/guides/limitations) for the full threat
model and operator boundaries.

---

## Why quality matters

A code generator has a wide blast radius: a subtle regression touches every project that runs it.
These are the layers that catch problems before they reach you.

- **Generated validation is differentially tested against the spec, not just compiled.** Behavioral
  round-trip tests load the generated classes into a real Laravel app (Orchestra Testbench) and run
  real payloads through Laravel's Validator (`tests/Feature/Emitter/RoundTripTest.php`), and the
  differential validation oracle (below) asserts the contract directly: what the spec rejects, the
  generated `rules()` must reject, and what the spec accepts, they must accept. The
  [e2e suite](#proof-a-full-contract-first-round-trip) goes further and proves `422` responses over
  real HTTP from the spec-derived `rules()`.
- **130 real-world specs, multiple gates.** The corpus is the published OpenAPI documents of Stripe,
  GitHub, OpenAI, Slack, Twilio, and 125 others. Every one must *parse*, *generate model classes,
  controllers, and routes that compile* (`php -l`, which goes a step further than tokenizing), and
  *resolve every class reference*, on every CI run. As a 0.7.0 robustness sweep, the generator was
  also run over 9 large public API specs as test inputs (Stripe, GitHub, Box, Adyen, Asana, Sentry,
  Twilio among them): all 13,378 generated files compile clean, and two construct-diverse specs
  (Sentry, Twilio v2010) joined the permanent corpus.
- **Conformance golden test.** A single synthetic OpenAPI 3.1 spec exercises the full generator
  surface (exclusive bounds, `multipleOf`, `uniqueItems`, float enums, multi-type unions, strict
  date-time, defaults, non-object aliasing, union arrays). A golden test pins the per-construct
  output, runs `php -l` on it, and verifies byte-for-byte determinism on every CI run. This paid
  for itself immediately: it caught bug #24 (the array-of-union `DataCollectionOf` attribute,
  wrong at runtime) before the release.
- **PHPStan at max level**, **100% type coverage**, and **Laravel Pint** style enforcement, on every
  PR.
- **Mutation testing** with [Pest's native mutation testing](https://pestphp.com/docs/mutation-testing)
  (`composer test:mutate`, a dedicated CI job) deliberately introduces bugs into covered code and
  fails if the suite does not catch them, with a minimum score of 90%.
- **Dependency hygiene**: `composer-unused`, `composer-require-checker`, and `composer audit`
  (known-advisory scan) keep the dependency surface honest.
- **Architecture boundaries** enforced with [Deptrac](https://github.com/deptrac/deptrac)
  (`composer deptrac`, a dedicated CI job): Parser and Naming depend on nothing internal, and the
  dependency direction flows inward from Console toward those leaf layers.
- **Static code quality** via [Qodana](https://www.jetbrains.com/qodana/) (JetBrains), reported to
  the GitHub Security tab. This check is informational and does not block merges.
- **Committed snapshots** of generated output, diff-checked so any change to the generator is
  visible in review.
- **Drift check** (`openapi:check`) regenerates the full file set in memory and compares it
  byte-for-byte against disk, without writing anything. Exit `0` = in sync, `1` = drift detected
  (the build fails), `2` = config or spec error. Use it in CI so committed generated code can never
  silently diverge from the spec. See the
  [drift-check guide](https://openapi-laravel.codewithagents.de/guides/drift-check).
- **Differential validation oracle** (shipped in 0.7.0, issue #23): a data-driven, mutation-based
  test generates a class per constraint and runs valid and invalid payloads through the real Laravel
  Validator. A silently-dropped constraint fails the suite. A known-gap ratchet documents every
  acknowledged gap, so new gaps cannot accumulate silently. It found and drove several of the
  correctness fixes in 0.7.0 before release.

> Qodana Cloud dashboard (optional, maintainer only): create a project at
> [qodana.cloud](https://qodana.cloud) and add its token as the `QODANA_TOKEN` repository secret.
> The Qodana workflow already runs and reports to GitHub without it; the token only adds the Cloud
> dashboard upload.

---

## Roadmap

**Current release: `0.10.0`** on Packagist (`0.11.0` is queued in an open release-please PR). The
full feature set described above ships today: models, spec-derived validation, enums, controllers,
and routes out of the box; the drift gate (`openapi:check`); the differential validation oracle;
discriminated object-union validation and hydration in all three forms (named-component,
inline-union, allOf-inheritance, issue #38); default `additionalProperties: false` enforcement
(opt out with `--no-enforce-closed-objects`); subset generation (`--only-tags` / `--only-schemas`
with dependency closure, issue #44, plus the repeatable `--exclude-path-prefix` exclusion filter,
issue #96); self-contained output (the support classes inlined into the
consumer's own namespace, so generated code has no runtime dependency on the generator package,
issue #40); and the config surfaces (`config/openapi-laravel.php` and the standalone
`openapi-laravel.json`).

Next up (the pre-1.0 milestone):

- **Typed and validated query parameters** (issue #63): `in: query` parameters currently produce
  no typing, no rules, and no warning.
- **Spec response status codes in the scaffold** (issue #64): a `201`/`202` declared in the spec
  should come back from the scaffold without a hand-written workaround.
- **Warnings for every silent degradation** (issue #67): every fallback to `mixed` or
  `Illuminate\Http\Request` hits the warnings channel.
- **`@internal` PHP class API** (issue #69): the CLI is the public surface, the classes are not.
- **`minProperties` / `maxProperties` and dead normalization** (issue #72).
- **Visible empty Data classes** (issue #95): a TODO marker and a build warning instead of a
  silently empty class.

The version stays in `0.x` while the generated output format is still evolving, and tags `1.0.0`
(the output-stability promise described in the
[versioning policy](https://openapi-laravel.codewithagents.de/guides/versioning-policy)) only when
the format settles. See [ROADMAP.md](./ROADMAP.md) for the release history and the decisions
already locked in.

---

## License

[MIT](./LICENSE) © codewithagents
