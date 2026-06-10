# openapi-laravel

Generate Laravel models from your OpenAPI spec. The spec is the source of truth, your code follows it.

Takes an OpenAPI 3.0/3.1 document and emits [spatie/laravel-data](https://github.com/spatie/laravel-data)
classes with explicit, spec-derived validation rules, plus native PHP enums. Readable, deterministic,
owned output: the generated code lives in your repo and looks like code you would have written yourself.

```php
// generated from components.schemas.Customer
final class CustomerData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $email,
        public readonly CustomerStatus $status,
    ) {}

    public static function rules(): array
    {
        return [
            'name'   => ['required', 'string', 'max:255'],
            'email'  => ['nullable', 'email'],
            'status' => ['required', Rule::enum(CustomerStatus::class)],
        ];
    }
}
```

Sibling project of [openapi-zod-ts](https://github.com/codewithagents/openapi-zod-ts), which does
the same for TypeScript. Both are validated against a corpus of 128 real-world public API specs
(Stripe, GitHub, OpenAI, Slack, and friends).

## Status

Early. Scaffolding and roadmap only, see [ROADMAP.md](./ROADMAP.md). Not yet published to Packagist.

## Planned

- **v1**: models. Spec -> laravel-data classes + validation rules + enums, via `php artisan openapi:generate`.
- **v2**: server scaffold. Generated routes file + abstract controllers per tag, typed by the v1
  models. Your routing table derives from the spec; path-level drift becomes impossible.

## License

MIT
