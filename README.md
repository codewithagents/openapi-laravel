# openapi-laravel

[![CI](https://github.com/codewithagents/openapi-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/codewithagents/openapi-laravel/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](./LICENSE)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg)](https://php.net)

> Generate Laravel models and a server scaffold from your OpenAPI spec. The spec is the source of truth, your code follows it.

You consume or implement a REST API described by an OpenAPI document. You need PHP DTOs, validation
rules, and enums that match the spec exactly, and you need them to stay in sync every time the spec
changes. Instead of hand-writing and re-checking all of it, you run one command. The output is
[spatie/laravel-data](https://github.com/spatie/laravel-data) classes with explicit, spec-derived
`rules()` methods plus native PHP enums, and, when you opt in, an abstract controller per tag and a
routes file so the request/response types and the routing table derive from the spec too.

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
same for TypeScript. Both are validated against a corpus of 128 real-world public API specs (Stripe,
GitHub, OpenAI, Slack, Twilio, and friends).

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
  `max`/`min`/`pattern`/`format` (email, uuid, date, url, ip), numeric `min`/`max`, array
  `min`/`max` items, and `Rule::enum` / `Rule::in`
- **Enums** → native PHP backed enums (string or int)
- **Naming** → StudlyCaps classes, camelCase properties, `#[MapName]` when the wire name differs,
  reserved-word and collision handling
- **Nested objects** → nested Data classes; **arrays** → `#[DataCollectionOf]` typed collections
- **Nullability** → both 3.0 `nullable` and 3.1 `type: [..., 'null']`
- **readOnly / writeOnly** → a read variant and a write variant, only when the spec uses the flags
- **`allOf`** → merged into one flat class, members unioned, `required` deduped, conflicts resolved
  deterministically
- **`additionalProperties`** → typed maps (`array<string, X>`) with per-value wildcard rules; a
  pure-map component is inlined at its use sites instead of emitting an empty class
- **`oneOf` / `anyOf`** → native PHP union types plus a variant docblock when every member resolves
  to a scalar or a generated Data class (`string|int`, `CatData|DogData`), with a deterministic
  `mixed` fallback for messier members
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
classes) does not auto-hydrate in laravel-data without a discriminator, an empty
`additionalProperties` map currently serializes as `[]` rather than `{}` (a known issue), a request
body referencing a component `$ref` falls back to `Illuminate\Http\Request` instead of a typed Data
param, and tuple `prefixItems`, int64 literal bounds, and non-JSON responses are represented loosely.
See the [limitations guide](https://openapi-laravel.codewithagents.de/guides/limitations) for the
full, honest list.

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
| `oneOf` / `anyOf` | **Union type hints** (object unions need a discriminator at runtime) | n/a | Partial | You do |
| Minimum Laravel version | **11** | 9+ | 10+ | n/a |
| Runtime peer dependency | `spatie/laravel-data` v4 | none | own DTO layer | none |
| Standard OpenAPI (no custom extensions) | **Yes** | Yes | No (custom OAS) | n/a |
| Owned, readable, committed output | **Yes** | n/a | Generated | Yes |
| Runs without Laravel (CI) | **Yes** (bin) | No | No | n/a |

The `spatie/laravel-data` v4 runtime peer is a real adoption cost: the generated DTOs are
laravel-data classes, so your app takes on that dependency and its conventions.

**Pick something else if:**

- your code is the source of truth and you want the spec generated from it (l5-swagger, scramble);
- you are on Laravel 10 or older, or your app standardizes on a DTO/validation layer other than
  `spatie/laravel-data` v4;
- you need a non-PHP target (the sibling
  [openapi-zod-ts](https://github.com/codewithagents/openapi-zod-ts) covers TypeScript).

---

## Proof: a full contract-first round trip

The strongest claim a generator can make is that its output actually interoperates over the wire. The
[`e2e/`](./e2e) directory works toward exactly that from a single spec. The Laravel half is proven
today; the TypeScript SPA half is being finalized.

**Proven today** (curl-verified over real HTTP):

```
  e2e/spec/petstore.yaml          (one OpenAPI document, the source of truth)
        │
        └── openapi-laravel  →  a real Laravel 12 backend
                                (Data classes + abstract controllers + routes)
                                      │
                                real HTTP, verified with curl
```

**Being finalized** (not yet proven, in progress):

```
  e2e/spec/petstore.yaml
        │
        └── openapi-zod-ts  →  a typed TypeScript client  →  a SPA
                                                                │
                          docker-compose stack + Playwright headless-Chrome E2E:
                          browser → SPA → generated client → backend
```

One spec, two languages, no hand-written types on either side of the wire. The demo deliberately
stresses the cross-language serialization seams where a typed client and a `laravel-data` server can
disagree. The following are proven to round-trip over real HTTP against the generated Laravel backend
today:

- a `snake_case` wire field forcing `#[MapName]`, mapped in both directions
- a `writeOnly` field accepted on write and never returned on read
- a `readOnly` `date-time` set server-side and ignored when the client sends it
- a `nullable` number where `null` stays present as `null`
- an `additionalProperties` string-to-string map that round-trips intact
- a `oneOf: [string, integer]` scalar union where a string stays a string and an integer stays an
  integer, with no coercion

It honestly reports the one seam quirk it surfaced: an empty `additionalProperties` map serializes as
`[]` rather than `{}` (the classic PHP empty-array ambiguity). Non-empty maps and `null` are correct.

Use it two ways: as **proof** that the generated Laravel backend behaves correctly over real HTTP,
and as a **template** a team can copy to bootstrap a spec-first project. The Laravel backend and its
contract round trip are proven and working today; the TypeScript SPA, the Docker stack, and the
headless-Chrome suite that close the full cross-language loop are being finalized, so [`e2e/`](./e2e)
is the living reference. Exact run-it-yourself commands land once the demo is fully green.

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

- **128 real-world specs, three gates.** Every spec in the corpus (Stripe, GitHub, OpenAI, Slack,
  Twilio, Adyen, and more) must *parse*, *generate syntactically valid model classes*, and
  *generate syntactically valid controllers and routes* on every CI run.
- **End-to-end round-trip.** Generated classes are loaded into a real Laravel app (Orchestra
  Testbench), hydrated from payloads, and validated. Valid payloads pass; a missing required field, a
  bad email, and an out-of-range number each throw `ValidationException`.
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

> Qodana Cloud dashboard (optional, maintainer only): create a project at
> [qodana.cloud](https://qodana.cloud) and add its token as the `QODANA_TOKEN` repository secret.
> The Qodana workflow already runs and reports to GitHub without it; the token only adds the Cloud
> dashboard upload.

---

## Roadmap

**Current release: `0.4.0`** on Packagist.

- **0.1.x: models.** Spec → laravel-data classes + validation rules + enums, nested objects,
  collections, and the readOnly/writeOnly split.
- **0.2.0: server scaffold.** Generated routes file + abstract controllers per tag, typed by the
  models, so the routing table derives from the spec.
- **0.3.0: composition + hardening.** `allOf` merge, `additionalProperties` typed maps, a full
  security pass (the spec treated as untrusted input), and edge-case fixes.
- **0.4.0 (current): `oneOf` / `anyOf` union types** and the cross-language end-to-end demo.
- **v3 (maybe): client generation** built on the `Http::` facade. A decide-later item, not a
  commitment.

The version stays in `0.x` while the generated output format is still evolving, and tags `1.0.0`
(the API-stability promise) only when the feature set settles. See [ROADMAP.md](./ROADMAP.md) for the
full plan and the decisions already locked in.

---

## License

[MIT](./LICENSE) © codewithagents
