# Contract-first end-to-end demo

This directory is a runnable proof that `openapi-laravel` generates a real,
bootable backend from an OpenAPI spec. The spec is the single source of truth:
the models, the abstract controllers, and the routes are all generated from it.
The only hand-written PHP is the glue (concrete controllers + an in-memory
store) and the wiring.

This is milestone 1: the backend over real HTTP. Later milestones add a SPA,
Playwright, and Docker.

## Layout

```
e2e/
  spec/petstore.yaml          The shared contract. Source of truth for backend
                              (and, later, the frontend). Identical to the
                              repo's examples/petstore/openapi.yaml.
  backend/                    A real Laravel 12 application.
    app/Data/                 GENERATED Data classes (PetData, OrderData, ...).
    app/Http/Controllers/Api/ AbstractPet/Store/UserController are GENERATED;
                              the matching concrete controllers are hand-written.
    app/Http/Middleware/      CreatedResponse (promotes create 200 -> 201).
    app/Support/PetStore.php  Hand-written in-memory store (stands in for a DB).
    routes/api.generated.php  GENERATED route table, mounted under /api.
    config/openapi-laravel.php Generator config (points at ../spec/petstore.yaml).
    config/cors.php           Permissive CORS, DEMO ONLY (for the later SPA).
```

Generated files are committed on purpose: they are the proof artifact. The
Laravel `vendor/` directory is gitignored (run `composer install` to restore).

## How generation is wired (dogfooding the local package)

The backend consumes `codewithagents/openapi-laravel` from the local checkout,
not Packagist, via a Composer path repository in `backend/composer.json`:

```json
"repositories": [
    { "type": "path", "url": "../..", "options": { "symlink": true } }
],
"require": {
    "codewithagents/openapi-laravel": "@dev"
}
```

`../..` is the openapi-laravel repo root relative to `e2e/backend`. Composer
symlinks it into `vendor/codewithagents/openapi-laravel`, so the demo always
runs against the working tree. The published config (`config/openapi-laravel.php`)
points `spec` at `base_path('../spec/petstore.yaml')` and writes:

- Data classes -> `app/Data` (namespace `App\Data`)
- Abstract controllers -> `app/Http/Controllers/Api` (namespace `App\Http\Controllers\Api`)
- Routes -> `routes/api.generated.php`

Regenerate at any time with:

```bash
cd e2e/backend
php artisan openapi:generate --controllers --routes
```

Only the `Abstract*` controllers are ever (over)written; the concrete
controllers are never touched.

## Boot the backend

```bash
cd e2e/backend
composer install            # restore vendor/ (symlinks the local package)
php artisan serve --port=8088
```

The generated routes are mounted under `/api` (see `bootstrap/app.php`), so the
endpoints are real HTTP routes. `php artisan route:list` shows them all.

Note on state: `php artisan serve` uses PHP's built-in web server, where each
request is a fresh process, so the in-memory store does not persist writes
across requests. `PetStore` seeds two pets (id 1 Rex, id 2 Whiskers) at
construction so every request has deterministic data to read and delete. A
later milestone backing this with a persistent store can drop the seed.

## The curl proofs

With the server running on port 8088:

```bash
B=http://127.0.0.1:8088/api

# 1) GET list -> 200 (seeded)
curl -s -H 'Accept: application/json' "$B/pet/findByStatus?status=available"

# 2) POST a VALID body -> 201 with the created pet (auto-assigned id)
curl -s -X POST "$B/pet" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"name":"Buddy","photoUrls":["https://example.com/buddy.png"],"status":"available"}'

# 3) POST an INVALID body -> 422, validation from the GENERATED PetData rules()
curl -s -X POST "$B/pet" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"status":"on-fire"}'

# 4) GET one -> 200 existing, 404 missing
curl -s -H 'Accept: application/json' "$B/pet/1"
curl -s -o /dev/null -w '%{http_code}\n' -H 'Accept: application/json' "$B/pet/9999"

# 5) DELETE -> 204 existing, 404 missing
curl -s -o /dev/null -w '%{http_code}\n' -X DELETE -H 'Accept: application/json' "$B/pet/2"
curl -s -o /dev/null -w '%{http_code}\n' -X DELETE -H 'Accept: application/json' "$B/pet/9999"
```

### The headline proof: spec-derived validation over real HTTP

`POST /api/pet` with `{"status":"on-fire"}` returns `422`:

```json
{
  "message": "The name field is required. (and 2 more errors)",
  "errors": {
    "name": ["The name field is required."],
    "photoUrls": ["The photo urls field is required."],
    "status": ["The selected status is invalid."]
  }
}
```

Every one of those errors comes from the generated `app/Data/PetData::rules()`,
which the generator derived from the spec:

- `name` and `photoUrls` are required because the spec lists them in `required`.
- `status` must be one of `available|pending|sold` because the spec declares
  that enum, which became `Rule::in([...])`.

Bad input is rejected by the contract, before any hand-written code runs.

## CORS

`config/cors.php` is fully permissive (`paths: api/*`, origins/methods/headers
all `*`). This is DEMO ONLY, to let a later SPA milestone call the API from any
origin. A real deployment must pin origins and tighten methods/headers.
