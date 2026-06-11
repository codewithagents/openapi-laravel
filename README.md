# openapi-laravel

[![CI](https://github.com/codewithagents/openapi-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/codewithagents/openapi-laravel/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/codewithagents/openapi-laravel)](https://packagist.org/packages/codewithagents/openapi-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/codewithagents/openapi-laravel)](https://packagist.org/packages/codewithagents/openapi-laravel)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](./LICENSE)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg)](https://php.net)

> Generate Laravel models and a server scaffold from your OpenAPI spec. The spec is the source of truth, your code follows it.

**Documentation: [openapi-laravel.codewithagents.de](https://openapi-laravel.codewithagents.de)**

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://openapi-laravel.codewithagents.de/media/infographic-dark.svg">
  <img alt="From OpenAPI spec to generated Data classes, validation rules and controllers; you write only business logic; openapi:check fails CI on drift and regeneration never touches your files" src="https://openapi-laravel.codewithagents.de/media/infographic-light.svg" width="1200">
</picture>

Hand-written DTOs, validation rules, and controllers drift from the API contract silently. Nobody
notices the missing `nullable`, the renamed wire field, or the new enum case until production rejects
a valid payload, or a consumer files the bug for you. The spec already states every one of those
shapes; the drift exists only because humans re-type them.

So make the spec the source of truth and regeneration the sync mechanism. One command emits
[spatie/laravel-data](https://github.com/spatie/laravel-data) classes with explicit, spec-derived
`rules()` methods plus native PHP enums, and, when you opt in, an abstract controller per tag and a
routes file so the request/response types and the routing table derive from the spec too. When the
spec changes, you re-run the generator and review the diff.

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
same for TypeScript. Both are tested against the same corpus of 128 real-world public API documents
(detailed in [Why quality matters](#why-quality-matters) below).

The generated classes extend `Spatie\LaravelData\Data`, so
[spatie/laravel-data](https://github.com/spatie/laravel-data) v4 is a **runtime peer dependency of
your app** (a normal `require`, not `require --dev`). The generator itself is a dev dependency.

---

## Install

The generator is a dev dependency; `spatie/laravel-data` is a runtime dependency of your app because
the generated classes extend it:

```bash
composer require spatie/laravel-data:^4.0
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

The artisan command writes into the namespace from `config/openapi-laravel.php` (`output.namespace`,
default `App\Data`); set it there rather than on the command line. Set `spec` and `output.path` in the
config too and you can then just run `php artisan openapi:generate`.

Want the server scaffold too? Pass `--controllers` and `--routes` to also emit one abstract
controller per tag and a `routes/api.generated.php` file, typed by the same Data classes:

```bash
php artisan openapi:generate --controllers --routes
```

Not a Laravel project? The same generator ships as a framework-free binary:

```bash
vendor/bin/openapi-laravel --spec=openapi.yaml --output=src/Data --namespace="Acme\\Dto"
```

![openapi-laravel generating typed Data classes from an OpenAPI spec](https://openapi-laravel.codewithagents.de/media/openapi-laravel-demo.gif)

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
`--namespace`, `--controllers`, `--routes`) and only compares generator-owned files, so hand-written
concrete controllers are never flagged as drift.

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
     --controllers      →  app/Http/Controllers/Api/AbstractCustomerController.php
     --routes           →  routes/api.generated.php
```

You write your business logic. The DTOs, their validation, the controller signatures, and the
routing table stay in sync when the spec changes.

What the generator handles today:

- **Objects** → `laravel-data` classes with promoted, readonly constructor properties
- **Validation** → explicit `rules()` derived from the spec: `required`/`nullable`, types, string
  `max`/`min`/`pattern`/`format` (email, uuid, url, ip), numeric `min`/`max`, **exclusive bounds**
  (`gt`/`lt` for `exclusiveMinimum`/`exclusiveMaximum`, both the 3.0 boolean and 3.1 numeric forms),
  **`multipleOf`**, array `min`/`max` items and **`uniqueItems`** (`distinct`), and
  `Rule::enum` / `Rule::in`
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
  serializes as `{}` (not `[]`)
- **`oneOf` / `anyOf`** → a **scalar** union emits a native PHP union type plus a variant docblock
  (`string|int`); an **object** union (Data-class members) is typed `mixed` and validated for
  presence only, with the variant union kept in the `@var` docblock (`/** @var CatData|DogData */`).
  Object unions are presence-only so every valid variant is accepted, no valid payload is
  false-rejected (issue #31); full discriminator-aware variant validation and hydration is 1.0.0 work.
  Messier members fall back deterministically to `mixed`. An array whose `items` are a union emits a
  plain typed `array<int, A|B>` with a docblock, not `#[DataCollectionOf(A|B::class)]`: that attribute
  is syntactically accepted by PHP but resolves wrongly at runtime due to operator precedence (bug #24,
  fixed in 0.6.0)
- **Multi-type scalars** → `type: ["string","integer"]` becomes a `string|int` union (`["x","null"]`
  stays a nullable scalar)
- **Non-object components** → a top-level component that is itself a scalar, an array, or a
  `oneOf`/`anyOf` union is aliased to its underlying type at every `$ref` use site, instead of an
  empty Data class that would silently fail to hydrate
- **Server scaffold** (opt-in via `--controllers` / `--routes`) → an abstract controller per tag and
  a routes file, typed by the Data classes, so an unimplemented operation is a PHP fatal and
  path-level drift is structurally impossible
- **Determinism** → same spec in, byte-identical files out

With `--controllers --routes`, the same spec also produces a typed abstract controller per tag and a
routes file. An operation you forget to implement is a PHP fatal at class-definition time, not a gap
discovered in production:

```php
// generated: app/Http/Controllers/Api/AbstractPetController.php
// (this Pet schema marks some fields readOnly/writeOnly, so the request type is
//  the write variant PetWritableData and the response type is the read variant
//  PetData; a schema with no such flags would use a single PetData both ways)
abstract class AbstractPetController
{
    abstract public function addPet(PetWritableData $pet): PetData;
    abstract public function getPetById(int $petId): PetData;
    abstract public function deletePet(int $petId): JsonResponse;
}

// generated: routes/api.generated.php
Route::post('/pet', [PetController::class, 'addPet']);
Route::get('/pet/{petId}', [PetController::class, 'getPetById']);
Route::delete('/pet/{petId}', [PetController::class, 'deletePet']);
```

You write only the concrete `PetController extends AbstractPetController`. See the
[server scaffold guide](https://openapi-laravel.codewithagents.de/guides/server-scaffold) for the
full walkthrough.

A few OpenAPI features degrade gracefully rather than crash. An object union (`oneOf` of Data
classes) is typed `mixed` and validated for presence only: every valid variant is accepted (no valid
payload is false-rejected, issue #31), but the variant is neither enforced nor auto-hydrated in
laravel-data without a discriminator (1.0.0 work). A `$ref`-valued
`additionalProperties` map is typed in the docblock but not auto-hydrated into Data objects at
runtime, a request body referencing a component `$ref` falls back to `Illuminate\Http\Request`
instead of a typed Data param, and tuple `prefixItems`, int64 literal bounds, and non-JSON responses
are represented loosely. A non-standard per-property `required: true` key (a boolean set inside an
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

**Modern only.** PHP 8.2+, Laravel 11/12, laravel-data v4. No legacy shims.

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
| Spec-derived validation `rules()` | **Yes** | No | Partial | You do |
| Native PHP enums | **Yes** | No | No | You do |
| Server scaffold (abstract controllers + routes) | **Yes** (opt-in) | No | Yes | You do |
| `allOf` / `additionalProperties` | **Yes** | n/a | Partial | You do |
| `oneOf` / `anyOf` | **Scalar union type hints**; object unions presence-only, no false-reject (discriminator validation/hydration is 1.0.0 work) | n/a | Partial | You do |
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

- **Generated validation is executed, not just compiled.** Behavioral round-trip tests load the
  generated classes into a real Laravel app (Orchestra Testbench) and run real payloads through
  Laravel's Validator: valid payloads pass, while a missing required field, a malformed email, and a
  below-minimum integer each throw `ValidationException`
  (`tests/Feature/Emitter/RoundTripTest.php`). The [e2e suite](#proof-a-full-contract-first-round-trip)
  goes further and proves `422` responses over real HTTP from the spec-derived `rules()`.
- **128 real-world specs, multiple gates.** The corpus is the published OpenAPI documents of Stripe,
  GitHub, OpenAI, Slack, Twilio, and 123 others. Every one must *parse*, *generate model classes,
  controllers, and routes that compile* (`php -l`, which goes a step further than tokenizing), and
  *resolve every class reference*, on every CI run.
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
- **Next rigor step (roadmap):** a differential conformance harness that derives a conforming and a
  violating payload per emitted constraint and asserts Validator agreement across the corpus
  ([#23](https://github.com/codewithagents/openapi-laravel/issues/23)). Today the executed-validation
  guarantee covers the behavioral suite and the e2e demo, not every constraint of every corpus spec.

> Qodana Cloud dashboard (optional, maintainer only): create a project at
> [qodana.cloud](https://qodana.cloud) and add its token as the `QODANA_TOKEN` repository secret.
> The Qodana workflow already runs and reports to GitHub without it; the token only adds the Cloud
> dashboard upload.

---

## Roadmap

**Current release: `0.6.0`** on Packagist.

- **0.1.x: models.** Spec → laravel-data classes + validation rules + enums, nested objects,
  collections, and the readOnly/writeOnly split.
- **0.2.0: server scaffold.** Generated routes file + abstract controllers per tag, typed by the
  models, so the routing table derives from the spec.
- **0.3.0: composition + hardening.** `allOf` merge, `additionalProperties` typed maps, a full
  security pass (the spec treated as untrusted input), and edge-case fixes.
- **0.4.0: `oneOf` / `anyOf` union types** and the cross-language end-to-end demo.
- **0.5.0: hardening.** A silent-validation correctness pass (exclusive bounds, `multipleOf`,
  `uniqueItems`, float enums, multi-type unions, strict date-time, defaults), non-object component
  aliasing (no more empty Data classes), parser hardening (boolean `items`, clean OOM message),
  empty-map `{}` encoding, a `--namespace` flag, and a `php -l` compile gate.
- **0.6.0 (current): drift check + quality.** `php artisan openapi:check` (and
  `vendor/bin/openapi-laravel check`) regenerates the full file set in memory and compares it
  byte-for-byte against disk, without writing anything. Exit `0` = in sync, `1` = drift, `2` =
  config or spec error. Generate and check share one code path (a `GenerationPlanner`) so they can
  never compute a different result. Also ships: a conformance golden test that pins per-construct
  output and catches regressions, and a fix for bug #24 (arrays whose `items` are a
  `oneOf`/`anyOf` union now emit a plain typed `array<int, A|B>` with a docblock, not a
  `#[DataCollectionOf(A|B::class)]` attribute that is wrong at runtime).
- **v3 (maybe): client generation** for consuming a third-party or internal API (e.g. a typed
  PayPal/Stripe/microservice client), built on the `Http::` facade. A decide-later item, not a
  commitment.

The version stays in `0.x` while the generated output format is still evolving, and tags `1.0.0`
(the API-stability promise) only when the feature set settles. See [ROADMAP.md](./ROADMAP.md) for the
full plan and the decisions already locked in.

---

## License

[MIT](./LICENSE) © codewithagents
