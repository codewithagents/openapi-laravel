# Contract-first end-to-end demo

This directory is a runnable proof that `openapi-laravel` generates a real,
bootable backend from an OpenAPI spec. The spec is the single source of truth:
the models (read + writable variants), the abstract controllers, and the routes
are all generated from it. The only hand-written PHP is the glue (concrete
controllers + a file-backed store) and the wiring.

- Milestone 1: the backend over real HTTP.
- Milestone 2 (this one): a persistent store and a "petstore-plus" spec that
  deliberately stresses the cross-language serialization seams where a typed
  client and the laravel-data server must agree (MapName, writeOnly/readOnly
  variants, nullable, additionalProperties maps).
- Milestone 2.5: a `oneOf` scalar union field (`external_id`), proving the
  generator's union-type support round-trips end to end over the wire.

Later milestones add a SPA, Playwright, and Docker.

## Layout

```
e2e/
  spec/petstore.yaml          The shared contract ("petstore-plus"). Source of
                              truth for backend (and, later, the frontend).
  backend/                    A real Laravel 12 application.
    app/Data/                 GENERATED Data classes. PetData (read variant) and
                              PetWritableData (write variant) plus the rest.
    app/Http/Controllers/Api/ AbstractPet/Store/UserController are GENERATED;
                              the matching concrete controllers are hand-written.
    app/Http/Middleware/      CreatedResponse (promotes create 200 -> 201).
    app/Support/PetStore.php  Hand-written file-backed JSON store (stands in for
                              a DB; persists across requests/processes).
    routes/api.generated.php  GENERATED route table, mounted under /api.
    routes/console.php        petstore:reset command (reseed the store).
    config/openapi-laravel.php Generator config (points at ../spec/petstore.yaml).
    config/cors.php           Permissive CORS, DEMO ONLY (for the later SPA).
```

Generated files are committed on purpose: they are the proof artifact. The
Laravel `vendor/` directory and the runtime store file
(`storage/app/petstore.json`) are gitignored.

## The petstore-plus seams

The `Pet` schema in `spec/petstore.yaml` adds five fields on top of the stock
petstore, each targeting a known cross-language mismatch source:

| Field          | Spec trait                         | What it proves |
|----------------|------------------------------------|----------------|
| `microchip_id` | snake_case wire name               | `#[MapName]` maps to the `microchipId` property and back, both directions |
| `secret_note`  | `writeOnly: true`                  | lands on `PetWritableData`, accepted on create, never in any read response |
| `created_at`   | `format: date-time`, `readOnly`    | dropped from `PetWritableData`, set server-side, ignored if a client sends it |
| `weight_kg`    | `number`, `nullable: true`         | a null is accepted and stays present (as `null`) in responses |
| `attributes`   | `object` + `additionalProperties`  | a string->string map round-trips intact |
| `external_id`  | `oneOf: [string, integer]`         | a scalar union: a string stays a string and an integer stays an integer over the wire |

The `external_id` field exercises the generator's `oneOf`/`anyOf` support. For a
scalar union it emits a real PHP union type, not bare `mixed`:

```php
/** @var string|int */
#[MapName('external_id')]
public readonly string|int|null $externalId = null,
```

with a presence-only rule (`'external_id' => ['sometimes']`). A scalar union is
chosen deliberately: object unions cannot auto-hydrate in laravel-data without a
spec `discriminator` (a documented residual, the generator types those as
`mixed`), whereas a scalar union round-trips cleanly and proves the union path
end to end.

Because the spec now uses `readOnly`/`writeOnly`, the generator emits a
read/write split: `PetData` (response shape, has `created_at`, no `secret_note`)
and `PetWritableData` (request shape, has `secret_note`, no `created_at`). The
generated `AbstractPetController::addPet` therefore takes a `PetWritableData`
and returns a `PetData`. The concrete controller does the one thing the contract
cannot: assign `created_at` server-side and copy field-by-field into a `PetData`
(which drops `secret_note` by construction).

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

## Persistence

The store is a single JSON file at `storage/app/petstore.json`, read-modify-write
on every mutation, with `LOCK_EX`. The reason: `php artisan serve` (PHP's built-in
server) and the later Docker/php-fpm setup each handle requests in separate
workers/processes, so an in-process array would reset between requests and a
create in one request would be invisible to a list in the next. A file survives.

Pets persist through the generated `PetData` read-model: `toArray()` on the way
to disk (so `created_at` and the snake_case wire names are what land in the file)
and `PetData::from()` on the way back. The store seeds two pets on first use
(Rex #1, Whiskers #2). Reseed at any time:

```bash
php artisan petstore:reset
```

## Boot the backend

```bash
cd e2e/backend
composer install            # restore vendor/ (symlinks the local package)
php artisan petstore:reset  # optional: start from the known seed
php artisan serve --port=8088
```

The generated routes are mounted under `/api` (see `bootstrap/app.php`), so the
endpoints are real HTTP routes. `php artisan route:list` shows them all.

## Run the whole stack in Docker

One command brings up the generated backend and the SPA together, reachable so a
real browser can drive the SPA which calls the backend over real HTTP:

```bash
docker compose -f e2e/docker-compose.yml up -d --build
# backend:  http://localhost:8088   (API under /api, health at /up)
# frontend: http://localhost:8080   (the built SPA)
docker compose -f e2e/docker-compose.yml down
```

Both services run the committed code as-is: the containers do NOT regenerate the
backend Data classes or the frontend client. The generated artifacts that ship
in the repo are the proof, and the images just boot them.

### How the path-repo problem is solved (backend)

The local dev setup consumes `codewithagents/openapi-laravel` through a Composer
PATH repository pointing at `../..` (the package working tree, symlinked). That
path does not exist inside a container. The key insight: the package is a
GENERATION-TIME tool (the `openapi:generate` command); it is NOT needed at
runtime. The generated `app/Data` classes depend only on `spatie/laravel-data`,
and the controllers/routes are plain Laravel.

So in `backend/composer.json`:

- `codewithagents/openapi-laravel` lives in `require-dev` (behind the path repo).
- `spatie/laravel-data` is a direct runtime `require` (the generated Data classes
  extend `Spatie\LaravelData\Data`, so it is a genuine runtime dependency of the
  committed generated code, independent of the generator tool).

The backend image then runs `composer install --no-dev`, which never resolves the
dev-only generator and therefore never touches the missing `../..` path. Local
development is unchanged: a normal `composer install` still symlinks the package
in, and `php artisan openapi:generate --controllers --routes` still regenerates
byte-identical output.

### Image structure and port wiring

- `backend/Dockerfile`: `php:8.3-cli`, the extra Laravel extensions (`bcmath`,
  `intl`, `zip`; the rest are bundled), Composer, `composer install --no-dev
  --optimize-autoloader`, an APP_KEY generated at build time, writable
  `storage/` + the `storage/app` file-store dir, and `php artisan serve` on 8088.
  It ships `backend/.env.docker` (array/file session/cache/queue drivers, no
  database server: the demo is fully file-backed via `storage/app/petstore.json`)
  and seeds the deterministic store (`petstore:reset`) on container start.
- `frontend/Dockerfile`: multi-stage. Node 20 + pnpm to `pnpm install
  --frozen-lockfile` and `pnpm build` (the generated client in `src/api` is
  committed, so the build only typechecks and bundles it), then nginx serves the
  static `dist/`.
- `docker-compose.yml`: `backend` (host `8088`) and `frontend` (host `8080`) on a
  shared bridge network, each with a healthcheck; the frontend waits for the
  backend to be healthy.

The load-bearing detail is `VITE_API_BASE`. The SPA's `fetch` runs in the user's
BROWSER, not inside a container, so the API base must be a HOST-reachable URL, not
the internal compose service name. The frontend image therefore bakes
`VITE_API_BASE=http://localhost:8088/api` (the host-mapped backend port) at build
time via a build arg. The backend's permissive demo CORS lets the cross-origin
call (`localhost:8080` -> `localhost:8088`) through.

## The seam proofs (real HTTP, port 8088)

Each block below was captured live. IDs assume a fresh `petstore:reset` (seeds
are #1 Rex and #2 Whiskers, so the first create is #3).

### 1) MapName round-trip (`microchip_id`)

```bash
curl -s -X POST "$B/pet" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"name":"Mappy","photoUrls":["https://example.com/m.png"],"status":"available","microchip_id":"chip-xyz-999"}'
# 201 -> {... "microchip_id":"chip-xyz-999", "created_at":"2026-06-10T17:30:02+00:00", ...}
curl -s "$B/pet/3"
# 200 -> "microchip_id":"chip-xyz-999"   (mapped back from the microchipId property)
```

### 2) writeOnly `secret_note` (accepted, never read back)

```bash
curl -s -X POST "$B/pet" ... -d '{... ,"secret_note":"do-not-leak-this"}'
# 201 -> response has NO secret_note
curl -s "$B/pet/4" | grep secret_note    # (empty: not present in the read)
```

### 3) readOnly `created_at` (server-set, client value ignored)

```bash
curl -s -X POST "$B/pet" ... -d '{... ,"created_at":"1999-12-31T23:59:59+00:00"}'
# 201 -> "created_at":"2026-06-10T17:30:16+00:00"   (server time, NOT 1999)
```

### 4) nullable `weight_kg` (null stays present)

```bash
curl -s -X POST "$B/pet" ... -d '{... ,"weight_kg":null}'   # 201 -> "weight_kg":null
curl -s -X POST "$B/pet" ... -d '{... ,"weight_kg":42.7}'   # 201 -> "weight_kg":42.7
```

### 5) `attributes` map round-trip

```bash
curl -s -X POST "$B/pet" ... -d '{... ,"attributes":{"color":"black","size":"large","mood":"sleepy"}}'
# 201/200 -> "attributes":{"color":"black","size":"large","mood":"sleepy"}
```

### 6) enum 422 still fires

```bash
curl -s -X POST "$B/pet" ... -d '{"name":"Bad","photoUrls":["..."],"status":"on-fire"}'
# 422 -> {"message":"The selected status is invalid.","errors":{"status":["The selected status is invalid."]}}
```

### 7) DELETE -> 204

```bash
curl -s -o /dev/null -w '%{http_code}\n' -X DELETE "$B/pet/2"     # 204
curl -s -o /dev/null -w '%{http_code}\n' -X DELETE "$B/pet/9999"  # 404
```

### 8) Persistence across separate requests

```bash
# request A
curl -s -X POST "$B/pet" ... -d '{"name":"Persisto","photoUrls":["..."],"status":"sold", ...}'   # 201, id 9
# request B (separate process)
curl -s "$B/pet/findByStatus?status=sold"
# 200 -> [ {... "name":"Persisto", "id":9, ...} ]   the create survived
```

### 9) oneOf scalar union (`external_id`) preserves its JSON type

The `string|int|null` union round-trips with the wire type intact in both
directions: a string stays a string, an integer stays an integer (no coercion).

```bash
# string variant
curl -s -X POST "$B/pet" ... -d '{... ,"external_id":"ext-abc-123"}'
# 201/200 -> "external_id":"ext-abc-123"   (quoted string)

# integer variant
curl -s -X POST "$B/pet" ... -d '{... ,"external_id":778899}'
# 201/200 -> "external_id":778899          (bare integer, NOT "778899")

# omitted (optional)
curl -s -X POST "$B/pet" ... -d '{"name":"NoExt","photoUrls":["..."],"status":"available"}'
# 201/200 -> "external_id":null
```

The two seed pets also carry both variants: Rex #1 has `"ext-rex-legacy"`
(string), Whiskers #2 has `559900` (integer). laravel-data hydrates and emits
the `string|int|null` union without coercing either side, so the scalar union
is clean end to end.

## Known seam quirk (reported honestly)

An EMPTY `attributes` map round-trips as a JSON array `[]`, not an object `{}`:

```bash
curl -s -X POST "$B/pet" ... -d '{... ,"attributes":{}}'
# 201 -> "attributes":[]
```

This is the classic PHP ambiguity: an empty associative array is identical to an
empty list, so `json_encode` emits `[]`. A NON-empty map encodes correctly as an
object (proof 5). A strict typed client expecting `Record<string,string>` would
see an array for the empty case. The honest fixes live on either side of the
seam (a cast that forces object encoding, or a client that tolerates `[] | {}`),
and are noted here rather than papered over. `null` vs empty map is unaffected:
a null `attributes` stays `null`.

Note also that `PUT /pet` does a full replace (the write variant is the whole
resource), so fields omitted from a PUT body become null. `created_at` is the
exception: the controller preserves the original on update.

## CORS

`config/cors.php` is fully permissive (`paths: api/*`, origins/methods/headers
all `*`). This is DEMO ONLY, to let a later SPA milestone call the API from any
origin. A real deployment must pin origins and tighten methods/headers.
