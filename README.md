# openapi-laravel

[![CI](https://github.com/codewithagents/openapi-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/codewithagents/openapi-laravel/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](./LICENSE)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg)](https://php.net)

> Generate Laravel models from your OpenAPI spec. The spec is the source of truth, your code follows it.

You consume or implement a REST API described by an OpenAPI document. You need PHP DTOs, validation
rules, and enums that match the spec exactly, and you need them to stay in sync every time the spec
changes. Instead of hand-writing and re-checking all of it, you run one command. The output is
[spatie/laravel-data](https://github.com/spatie/laravel-data) classes with explicit, spec-derived
`rules()` methods plus native PHP enums.

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

---

## Install

```bash
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

Or set `spec` and `output.path` in `config/openapi-laravel.php` and just run
`php artisan openapi:generate`.

Not a Laravel project? The same generator ships as a framework-free binary:

```bash
vendor/bin/openapi-laravel --spec=openapi.yaml --output=src/Data --namespace="Acme\\Dto"
```

---

## Pipeline

```
openapi.yaml
  └── openapi-laravel  →  app/Data/CustomerData.php        (laravel-data class + rules())
                          app/Data/CustomerStatus.php      (native backed enum)
                          app/Data/CustomerWritableData.php (write variant, when the spec
                                                             uses readOnly/writeOnly)
```

You write your business logic. The DTOs and their validation stay in sync when the spec changes.

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
- **Determinism** → same spec in, byte-identical files out

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
| Standard OpenAPI (no custom extensions) | **Yes** | Yes | No (custom OAS) | n/a |
| Owned, readable, committed output | **Yes** | n/a | Generated | Yes |
| Runs without Laravel (CI) | **Yes** (bin) | No | No | n/a |

**Pick something else if** your code is the source of truth and you want the spec generated from it
(l5-swagger, scramble), or you need full server scaffolding today (on the roadmap here as v2).

---

## Why quality matters

A code generator has a wide blast radius: a subtle regression touches every project that runs it.
These are the layers that catch problems before they reach you.

- **128 real-world specs, two gates.** Every spec in the corpus (Stripe, GitHub, OpenAI, Slack,
  Twilio, Adyen, and more) must both *parse* and *generate syntactically valid PHP* on every CI run.
- **End-to-end round-trip.** Generated classes are loaded into a real Laravel app (Orchestra
  Testbench), hydrated from payloads, and validated. Valid payloads pass; a missing required field, a
  bad email, and an out-of-range number each throw `ValidationException`.
- **PHPStan at max level**, **100% type coverage**, and **Laravel Pint** style enforcement, on every
  PR.
- **Mutation testing** with [Infection](https://infection.github.io/) deliberately introduces bugs
  and checks the suite catches them.
- **Dependency hygiene**: `composer-unused` and `composer-require-checker` keep the dependency
  surface honest.
- **Committed snapshots** of generated output, diff-checked so any change to the generator is
  visible in review.

---

## Roadmap

- **v1 (current): models.** Spec → laravel-data classes + validation rules + enums.
- **v2: server scaffold.** Generated routes file + abstract controllers per tag, typed by the v1
  models. Your routing table derives from the spec, so path-level drift becomes structurally
  impossible.

See [ROADMAP.md](./ROADMAP.md) for detail.

---

## License

[MIT](./LICENSE) © codewithagents
