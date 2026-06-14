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
135 specs on this side (detailed in [Why quality matters](#why-quality-matters) below).

The generated classes extend `Spatie\LaravelData\Data`, so
[spatie/laravel-data](https://github.com/spatie/laravel-data) v4 is a **runtime peer dependency of
your app** (a normal `require`, not `require --dev`). The generator itself is a dev dependency.

---

## Install

The generator is a dev dependency; `spatie/laravel-data` is a runtime dependency of your app because
the generated classes extend it:

```bash
composer require spatie/laravel-data:^4.23
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
`--namespace`, `--no-controllers`, `--no-routes`) and
only compares generator-owned files, so hand-written concrete controllers are never flagged as
drift.

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

- **Objects** → `laravel-data` classes with promoted readonly constructor properties
- **Validation** → explicit `rules()` from the spec: required/nullable, types, string format/pattern, numeric bounds (inclusive and exclusive), `multipleOf`, `uniqueItems`, `dependentRequired`, `Rule::enum`/`Rule::in`
- **Enums** → native PHP backed enums (string or int); float enums emit `Rule::in`
- **Nested objects and collections** → nested Data classes; typed `#[DataCollectionOf]` arrays
- **`allOf`** → merged into one flat class with deduped `required`
- **`oneOf` / `anyOf`** → scalar unions emit native PHP union types; discriminated object unions emit an abstract morphable base plus per-variant classes (full validation and hydration); undiscriminated object unions are typed `mixed`, presence-only, no false-rejects
- **`additionalProperties`** → typed maps (`array<string, X>`); enforced closed-object validation by default, opt out with `--no-enforce-closed-objects`
- **readOnly / writeOnly** → separate read and write variants, only when the spec uses the flags (transitive: any descendant field triggers the split)
- **Query, path, and header parameters** → per-operation Data classes with spec-derived rules; delimited arrays split before validation; `deepObject` params synthesized as nested objects; integer path params get a `->whereNumber()` route constraint
- **Request bodies** → typed Data class for JSON, multipart, and form-urlencoded object bodies (inline or component `$ref`); `format: binary` parts typed `UploadedFile`
- **Response types** → inline and component `$ref` 2xx object responses typed as the controller return (read variant); non-200 success status honored via `RespondsWithStatus` middleware; 204 typed `void`
- **Server scaffold** → abstract controller per tag plus a routes file; unimplemented operations are PHP fatals; Laravel-convention method names for RESTful operations
- **Tag-grouped layout** → Data classes and enums in per-tag subdirectories matching their namespace; multi-tag schemas stay at the flat root
- **Drift gate** → `openapi:check` regenerates in memory and compares byte-for-byte; exit 0/1/2

For known limitations and graceful-degradation cases see the [limitations guide](https://openapi-laravel.codewithagents.de/guides/limitations).

The same spec also produces a typed abstract controller per tag and a routes file. An operation you
forget to implement is a PHP fatal at class-definition time, not a gap discovered in production:

```php
// generated: app/Http/Controllers/Api/AbstractPetController.php
// (this Pet schema marks some fields readOnly/writeOnly, so the request type is
//  the write variant PetWritableData and the response type is the read variant
//  PetData; a schema with no such flags would use a single PetData both ways)
abstract class AbstractPetController
{
    // clean RESTful operations get the conventional Laravel method names;
    // anything ambiguous or non-CRUD keeps its operationId-derived name
    abstract public function store(PetWritableData $pet): PetData;
    abstract public function show(int $petId): PetData;
    abstract public function destroy(int $petId): void;   // the spec declares 204: nothing to return
}

// generated: routes/api.generated.php
// (the spec declares 201 for the create and 204 for the delete, so those
//  routes carry the inlined status middleware; plain 200 operations stay untouched)
Route::post('/pet', [PetController::class, 'store'])->name('store')->middleware(RespondsWithStatus::class.':201');
Route::get('/pet/{petId}', [PetController::class, 'show'])->name('show');
Route::delete('/pet/{petId}', [PetController::class, 'destroy'])->name('destroy')->middleware(RespondsWithStatus::class.':204');
```

You write only the concrete `PetController extends AbstractPetController`, and
`php artisan openapi:scaffold` (or `vendor/bin/openapi-laravel scaffold`) writes its initial,
one-time stub for you. See the
[server scaffold guide](https://openapi-laravel.codewithagents.de/guides/server-scaffold) for the
full walkthrough.

A few OpenAPI features degrade gracefully rather than crash. An undiscriminated object union is typed `mixed` with presence-only validation (no false-rejects). Non-object request and response bodies fall back to `Illuminate\Http\Request` / `JsonResponse` with a generator warning. A non-standard per-property `required: true` boolean is ignored in favour of the schema-level `required` array, with a diagnostic on stderr. See the [limitations guide](https://openapi-laravel.codewithagents.de/guides/limitations) for the full, honest list.

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

- **Differential validation oracle.** A mutation-based test generates a class per spec constraint and runs valid and invalid payloads through the real Laravel Validator. A silently-dropped constraint fails the suite; a known-gap ratchet documents every acknowledged exception so gaps cannot accumulate silently.
- **135 real-world specs.** The corpus covers Stripe, GitHub, OpenAI, Slack, Twilio, and 130 others. Every spec must parse, emit PHP that compiles (`php -l`), and resolve every class reference on every CI run.
- **PHPStan max, 100% type coverage, mutation testing** (Pest native, 90% minimum score), Deptrac architecture enforcement, and Laravel Pint style on every PR.
- **Drift gate.** `openapi:check` regenerates in memory and compares byte-for-byte; committed output that diverges from the spec breaks CI.

See the [docs](https://openapi-laravel.codewithagents.de) for the full quality story.

---

## Roadmap

Current release is on Packagist (see the badge above); 0.13.0 is queued. The full feature set described in this README ships today.

Open before 1.0:

- **Whole-body raw binary request bodies** (issue #119): `application/octet-stream` falls back to `Illuminate\Http\Request`.
- **OpenAPI 3.2** (issue #102): full support is post-1.0; 3.2 specs generate today on a best-effort path with loud warnings.

The version stays `0.x` until the output format settles, then tags `1.0.0` per the [versioning policy](https://openapi-laravel.codewithagents.de/guides/versioning-policy). See [ROADMAP.md](./ROADMAP.md) for the release history and locked-in decisions.

---

## License

[MIT](./LICENSE) © codewithagents
