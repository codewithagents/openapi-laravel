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

Generated files are NOT committed. This demo behaves like a real consumer of
the generators: the business logic (concrete controllers, middleware, the store,
models, providers) is versioned, the mechanical layer (`app/Data/**`, the
`Abstract*Controller` classes, `routes/api.generated.php`, and the frontend's
`src/api/**`) is gitignored and regenerated from the spec on every run by
`e2e/generate.sh`. CI runs that script, then builds and drives the stack, so the
demo is a real regression gate on the generator output. The Laravel `vendor/`
directory and the runtime store file (`storage/app/petstore.json`) are gitignored
too.

Regenerate the full mechanical layer (backend and frontend) at any time:

```bash
cd e2e
./generate.sh
```

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

Generate first, then bring up the generated backend and the SPA together,
reachable so a real browser can drive the SPA which calls the backend over real
HTTP:

```bash
e2e/generate.sh                                       # regenerate from the spec
docker compose -f e2e/docker-compose.yml up -d --build
# backend:  http://localhost:8088   (API under /api, health at /up)
# frontend: http://localhost:8080   (the built SPA)
docker compose -f e2e/docker-compose.yml down
```

The containers do NOT regenerate: the Docker build contexts (`./backend`,
`./frontend`) do not include the spec or the path-repo generator, so generation
cannot happen inside the Dockerfiles. It runs on the host (or in CI) via
`generate.sh` BEFORE the build, and the images then COPY the freshly generated
output in. `e2e/e2e-tests/run.sh` runs `generate.sh` automatically before the
compose build (set `SKIP_GEN=1` to reuse existing generated files).

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
  --frozen-lockfile` and `pnpm build` (the generated client in `src/api` was
  written to disk by `generate.sh` before the build, so the build only typechecks
  and bundles committed glue plus generated client), then nginx serves the static
  `dist/`.
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

## Former seam quirk: empty map encoding (resolved)

Older generator output had the classic PHP ambiguity: an EMPTY `attributes` map
round-tripped as a JSON array `[]`, because an empty associative array is
identical to an empty list and `json_encode` emits `[]`. The generator now
attaches a `MapObjectTransformer` to `additionalProperties` map fields, which
forces object encoding for the empty case. Verified live against this stack:

```bash
curl -s -X POST "$B/pet" ... -d '{... ,"attributes":{}}'
# 201 -> "attributes":{}     (object, not [])
curl -s "$B/pet/<id>"
# 200 -> "attributes":{}     (survives the file-store persistence round trip)
```

A non-empty map still encodes as an object (proof 5), and `null` vs empty map
is unaffected: a null `attributes` stays `null`.

Note also that `PUT /pet` does a full replace (the write variant is the whole
resource), so fields omitted from a PUT body become null. `created_at` is the
exception: the controller preserves the original on update.

## Playwright e2e coverage

The Playwright suite (`e2e-tests/tests/petstore.spec.ts`) has two tiers. The UI
journeys drive the SPA in headless Chromium over real HTTP. The API-contract
tier (the `/lab/*` endpoints) hits the backend directly via Playwright's request
fixture for breadth over the runtime feature matrix: each `/lab` endpoint
validates a crafted body against the generated `rules()` and echoes the hydrated
object back, so a single POST proves BOTH validation (a violation 422s) and
serialization/hydration (a valid payload returns correctly shaped). The `lab`
tag and its schemas live in the same `spec/petstore.yaml`; its `LabController`
is a pure stateless echo (no PetStore).

This table is a LIVING DOC, not a CI gate. It is honest about what is GENERATED
versus CONSUMER-WRITTEN glue, and about what is PROVEN versus a RESIDUAL.

Status note: spatie/laravel-data serializes a `Data` object returned from a POST
as `201 Created`, so every `/lab` echo (and the pet/order creates) responds 201,
not 200. That is a laravel-data framework default, not a generator choice.

### UI-journey tier (SPA over real HTTP)

| Feature | Spec construct | What the assertion proves | Generated vs consumer | Status |
|---|---|---|---|---|
| MapName round-trip | `microchip_id` snake_case property | the value survives create -> read in the list and detail | generated `#[MapName]` | proven |
| writeOnly split | `secret_note: writeOnly` | the sent value never appears in any read | generated write/read split | proven |
| readOnly server-set | `created_at: readOnly, date-time` | detail shows a server-set timestamp, client value ignored | generated rules + consumer assigns value | proven |
| nullable scalar | `weight_kg: nullable` | a null stays present (rendered `null`) | generated | proven |
| additionalProperties map | `attributes` string->string map | a non-empty map round-trips into the detail panel | generated | proven |
| oneOf scalar union | `external_id: oneOf [string, integer]` | string stays string, integer stays integer | generated union type | proven |
| enum validation | `status` enum | an out-of-enum value 422s | generated `in:` rule | proven |
| multipart upload (#75) | `multipart/form-data` object body, `image: string/binary` + `contentMediaType: image/png` | a PNG posted via the generated client is stored and its URL appears on the pet; a non-PNG 422s | generated `UploadFileRequestData` (`UploadedFile` + `file`/`mimetypes` rules) + consumer stores the file | proven |
| upload image serving (#75, D) | same | the recorded `/storage/uploads/<file>` URL serves the bytes (200, `image/png`, byte-identical to the upload) | generated upload param + consumer stores on the public disk + `php artisan storage:link` at container start | proven |
| security middleware (#77) | `security: [pet_upload_key]` apiKey (`X-API-Key`) + `security.middleware_map` | upload is 401 without the key, succeeds with it | generated route carries the mapped middleware; consumer writes `ApiKey` enforcement + alias | proven |
| 204 No Content (#64) | `deletePet` declares `204` | DELETE returns exactly 204 with an empty body | generated `void` return + `RespondsWithStatus:204` | proven |
| response header (#114) | `X-Total-Count` header on `findByStatus` 200 | the header is present and its count matches the body length | NOT generated: generator only WARNS. Header set by consumer `TotalCountHeader` middleware. Proves consumer glue, not generator support. | residual |

### API-contract tier (`/lab/*`, raw HTTP)

| Feature | Spec construct | What the assertion proves | Generated vs consumer | Status |
|---|---|---|---|---|
| numeric bounds | `minimum`/`maximum`, `exclusiveMinimum`/`exclusiveMaximum`, `multipleOf` | valid round-trips; each violation (incl. the exclusive boundary value, non-multiple) 422s | generated `min`/`max`/`gt`/`lt` + `MultipleOfRule` | proven |
| string constraints | `minLength`/`maxLength`, `pattern` | valid round-trips; short/long/bad-pattern 422 | generated `min`/`max`/`regex` | proven |
| array constraints | `minItems`/`maxItems`, `uniqueItems` | valid round-trips; too few/too many/duplicate 422 | generated `min`/`max` + `distinct` | proven |
| string formats | `date`, `date-time`, `time`, `duration`, `email`, `uuid`, `hostname` | valid round-trips; each bad format 422 | generated `date_format`/`email`/`uuid` + `Rfc3339DateTimeRule`/`Rfc3339TimeRule`/`Iso8601DurationRule`/`HostnameRule` | proven |
| enum + const | `enum`, `const` | valid round-trips; out-of-enum and wrong-const 422 | generated `Rule::in([...])` (const becomes a single-value `in`) | proven |
| closed object | `additionalProperties: false` | a payload with an unknown key 422s | generated `NoUnknownPropertiesRule` | proven |
| presence + default | `required`, `nullable`, optional, `default` | required-missing 422; nullable/optional default to null; the spec `default` appears in the response; overriding it is honored | generated constructor defaults + `required`/`nullable`/`sometimes` rules | proven |
| typed map + empty map | `additionalProperties: {type: integer}` | a non-empty map round-trips; an EMPTY map serializes as `{}` not `[]`; a bad value 422 | generated typed map + `MapObjectTransformer` | proven |
| oneOf scalar union | `oneOf: [string, integer]` | BOTH variants hydrate without coercion | generated `string\|int` union type | proven |
| nested + collection | nested `$ref` object + `array` of `$ref` objects | nested object and the collection round-trip; a deep violation 422 | generated nested Data + `#[DataCollectionOf]` | proven |
| backed enum | named string `enum` component | round-trips; out-of-enum 422 | generated backed `enum` class + `Rule::enum(...)` | proven |
| allOf merged-flat | `allOf: [$ref base, inline object]` | both branches round-trip; a missing field from either branch 422 | generated flat-merged Data | proven |
| discriminated union | `oneOf` + `discriminator` (named-component) | each variant hydrates to its own shape by `kind`; a variant-specific rule still fires after morph | generated morphable abstract base + `morph()` | proven |
| discriminated union, UNKNOWN discriminator | same | an unmapped `kind` is rejected with 422 | generated `morph()` throws `ValidationException` on an unmapped value | proven (was a 500; fixed in #124 / PR #127) |
| component $ref request body (#110) + $ref response (#116) | `requestBody.$ref -> requestBodies/...` wrapping a schema `$ref`, and `response.$ref -> responses/...` wrapping the same | typed round-trip; the resolved component's rules 422 on violation | generated: both resolved to one typed `LabRefPayloadData` param + return | proven |
| non-200 success (#64), POST + Data | `202` declared on a POST returning a Data object | responds 202 | generated `RespondsWithStatus:202` (now normalizes any 2xx) | proven (was 201; fixed in #125 / PR #128) |

### Pass 4: parameter axis, more composition forms, server surfaces

These rows extend the API-contract tier with the request-parameter axis (query,
path, header), additional composition forms, the route-group wiring, and the
non-JSON response surfaces. All are raw-HTTP assertions via the request fixture.

| Feature | Spec construct | What the assertion proves | Generated vs consumer | Status |
|---|---|---|---|---|
| query params | `in: query` params with `enum` + `minimum`/`maximum` + `pattern` | a valid query round-trips; each violation (bad enum, below-min, above-max, bad pattern, missing required) 422s | generated per-operation query Data + `rules()` | proven (Stage-0 probe: query validation FIRES at runtime) |
| path param, valid | `in: path` integer param | an in-range path value round-trips | generated typed path binding | proven |
| path param constraint (#113) | `in: path` integer with `minimum`/`maximum` | an out-of-range path value 422s with `score` in the error bag | generated `LabPathPathData::fromRoute()`; the concrete controller calls it to fire the min/max | proven (shipped in #113) |
| header param (#121) | `in: header` param with `pattern` `^tok-[0-9]{4}$`, required | a valid token echoes; a bad value or a missing required header 422s with `x-lab-token` in the error bag | generated `LabHeaderHeaderData::fromHeaders()`; the concrete controller calls it to fire the pattern/required rule | proven (shipped in #121) |
| routes group (#71) | `routes.prefix: v1` + `routes.middleware` | only `/api/v1/*` is reachable (un-prefixed 404s) and the group middleware stamps `X-Route-Group: v1` | generated `Route::group([prefix, middleware])`; consumer writes the marker middleware | proven |
| inline object request body (#76) | inline `application/json` OBJECT body (no `$ref`) | the synthesized class validates (minLength, max, required) and round-trips | generated `<Operation>RequestData` from the inline schema | proven |
| shared inline-object $ref (#110/#116) | two operations referencing ONE `requestBodies` $ref to an inline object schema | both operations validate and round-trip through the SAME class with identical rules | generated single shared `<Component>RequestData` class | proven |
| inline-union discriminator (#38) | inline `oneOf` + `discriminator` (synthesized variant names) | each variant hydrates by `petType`; an unknown value 422s | generated morphable base + synthesized variants | proven |
| allOf-inheritance discriminator (#38) | `discriminator` over `allOf`-inheritance variants | each variant hydrates by `vehicleType`; the variant rule fires post-morph; an unknown value 422s | generated morphable base + inherited variants | proven |
| missing discriminator (#124) | discriminated union, discriminator property ABSENT | a MISSING discriminator 422s with the discriminator field in the error bag (covers both inline-union and allOf-inheritance forms) | generated morphable base: `morph()`'s default arm throws `ValidationException` when the value is null/unmapped; the controller-injection path routes through it before any 500 | proven (controller-injection path; the raw `from()` path is still a 500 residual per ROADMAP) |
| response union (#116) | success response `oneOf` of two Data classes | each branch returns its own shape correctly; the selector enum is validated | generated union return type | proven |
| anyOf scalar union | `anyOf: [boolean, integer]` | BOTH variants hydrate without coercion (bool stays bool, int stays int) | generated `bool\|int` union type | proven |
| plain-text response (#117/#118) | `text/plain` success response | the runtime serves `text/plain` with the right body | generated base `Response` return + warning; body is consumer-written | proven (honest typing: no Data return) |
| binary download (#117/#118) | `application/octet-stream` success response | the runtime serves octet-stream bytes | generated base `Response` return + warning; body is consumer-written | proven (honest typing) |
| multipart array of binary (#75 edge) | `multipart/form-data` with `photos: array<string/binary>` + an `album` field | two PNG parts + the field round-trip the count; a non-PNG part 422s via the per-file rule | generated `photos.*` `file`/`mimetypes` rules + non-binary field validation | proven |
| tuple prefixItems (#82) | `prefixItems: [string, integer(min:0)]` | a valid tuple round-trips; a wrong-type or below-min value at a position 422s | generated per-position rules (types as `array<int, mixed>`) | proven (per-position validation; the array element TYPE is still `mixed`) |
| user CRUD (#94) | clean RESTful `/user` surface (POST, GET/PUT/DELETE `/{username}`) | full create -> read -> update -> delete loop over raw HTTP: POST 201, GET 200 (exact body), PUT 204, DELETE 204, GET missing 404 | generated Laravel-convention method names (`store`/`show`/`update`/`destroy`) + routes; consumer backs them with the in-memory store | proven |
| array query param (#63) | `tags` required `array`, `explode: true` | the PHP bracket form `?tags[]=a&tags[]=b` reflects BOTH tag values (200); a missing required `tags` 422s with `tags` in the error bag | generated `FindPetsByTagsQueryData` (`array` + `array.*` rules) | proven (bracket form; see residual below) |

Every active row above is an assertion. Several of them (the unknown
discriminator, the 202-on-POST status, path-param and header-param validation,
and the missing discriminator on the controller path) were first shipped as
`test.fixme` holding the promised assertion because real behavior diverged; the
e2e suite surfaced them as bugs/gaps, they were fixed/shipped in the generator
(#124 / PR #127, #125 / PR #128, #113, #121), and the assertions are now active
and green. This is the suite working as intended: strict assertions that encode
the promised contract and fail until the contract actually holds.

One row remains a deliberately-parked `test.fixme`, holding the OpenAPI-ideal
contract with a loud comment so the limitation is documented without lying about
runtime behavior:

- array query param, OpenAPI explode repeated-key form (#63, PHP residual): the
  OpenAPI `explode: true` ideal is `?tags=a&tags=b`, but PHP collapses repeated
  query keys to the LAST value before the app sees them, so only the last tag
  survives. This is a PHP/runtime limitation, not a generator bug. The PASSING
  contract uses the PHP-native bracket form `?tags[]=a&tags[]=b` (active, green);
  the repeated-key ideal is parked as a clearly-labeled `.fixme`.

### #77 security middleware matrix (AND / OR / public), end-to-end

The single `security middleware (#77)` row above proves the simplest case (one
scheme on `uploadFile`). The full requirement-object semantics are proven on a
dedicated set of `/lab/secure-*` GET operations, all at the OPERATION level (no
document-level `security` block, which would stamp auth onto every existing
route). Two apiKey header schemes back the matrix: `lab_session` (`X-Lab-Session`,
token `sess-1234`) and `lab_team` (`X-Lab-Team`, token `team-1234`), mapped in
`config/openapi-laravel.php` `security.middleware_map` to the `lab-session` /
`lab-team` guard middleware.

| Operation | `security` | Generated route middleware | What the assertion proves | Status |
|---|---|---|---|---|
| `GET /lab/secure-single` | `[{ lab_session: [] }]` | `[lab-session]` | one required scheme: no header 401, valid token 200 | proven |
| `GET /lab/secure-and` | `[{ lab_session: [], lab_team: [] }]` | `[lab-session, lab-team]` | AND: both schemes in ONE requirement object apply; both headers 200, EACH one missing in turn 401 | proven |
| `GET /lab/secure-or` | `[{ lab_session: [] }, { lab_team: [] }]` | `[lab-session]` (first only) | OR: the generator enforces ONLY the FIRST requirement object and warns, dropping `lab_team`. Valid `X-Lab-Session` 200; `X-Lab-Session` MISSING but valid `X-Lab-Team` 401 (a real OR would accept this) | proven (documented caveat) |
| `GET /lab/secure-public` | `[]` | (none) | `security: []` is explicitly public: reachable with no creds (200) | proven |

The OR caveat is the load-bearing, possibly-surprising one and is asserted
explicitly. Route middleware is an AND-only stack and cannot express OR, so when
an operation declares multiple alternative requirement objects the generator
keeps only the first and emits, verbatim:

```
Operation GET /lab/secure-or: security declares 2 alternative requirements (OR),
which middleware cannot express; only the first alternative (lab_session) is
enforced, ignored: (lab_team).
```

So `/lab/secure-or` accepts a valid `X-Lab-Session` (first requirement) but
REJECTS a request that only carries a valid `X-Lab-Team` (second requirement),
even though a spec-true OR would admit it. The e2e test pins this exact behavior
so the divergence from the OpenAPI ideal stays visible and honest.

### Config-only OUTPUT features: `controllers.base_class` and `output.validation_trait` (#83)

Both are config-only switches that change the SHAPE of the generated output, with
no CLI flag and no spec construct. The e2e suite proves each one not by inspecting
the generated file but by asserting its OBSERVABLE effect over real HTTP, so the
test fails if the generator stops weaving the feature in OR if the woven form is
inert at runtime.

| Feature | Config | What the assertion proves | Generated vs consumer | Status |
|---|---|---|---|---|
| `controllers.base_class` | `controllers.base_class: App\Http\Controllers\Api\BaseApiController` | every generated-controller route carries an `X-Base-Class: openapi-laravel` response header (asserted on a `LabController` route AND a `PetController` route), so the generated abstract really `extends BaseApiController` and the inheritance is live at runtime | generated `abstract class Abstract*Controller extends BaseApiController`; consumer writes the base, which declares `BaseClassMarker` middleware via Laravel 12's `HasMiddleware` interface | proven |
| `output.validation_trait` | `output.validation_trait: App\Validation\CustomValidationMessages` | POST `/lab/trait-check` with a missing or malformed `code` 422s with `errors.code[0]` EQUAL to the trait's exact custom string, so the trait is woven into the generated Data class and laravel-data honors its static `messages()` | generated `use CustomValidationMessages;` body line in every Data class; consumer writes the trait | proven |

The base-class proof mechanism: `BaseApiController` is abstract and implements
`HasMiddleware`, returning `BaseClassMarker` from its static `middleware()`. The
generator emits each abstract as `extends BaseApiController`, the concrete
controllers extend the abstracts, and the generated routes dispatch in the
`[Controller::class, 'method']` array form, so controller middleware fires.
`BaseClassMarker` stamps `X-Base-Class: openapi-laravel` on the response. If the
generator stopped emitting the extends clause, the header would vanish and the
test would fail.

The validation-trait proof mechanism: a new `/lab/trait-check` operation echoes a
single required, pattern-constrained `code` (`^[A-Z]{3}-[0-9]{3}$`). Its generated
`LabTraitCheckData` carries `use CustomValidationMessages;`, whose static
`messages()` returns `code.required => 'CUSTOM: code is required'` and
`code.regex => 'CUSTOM: code is malformed'`. laravel-data discovers `messages()`
via `method_exists()` and feeds it to the Laravel validator, so the 422 body's
`errors.code[0]` is the exact custom string, byte-for-byte. The keys are scoped to
that one field so enabling the trait globally (it is woven into EVERY Data class)
does not change the message text any other test asserts on.

## Residual pins (documented fallbacks, pinned over HTTP)

The generator has a set of HONEST RESIDUALS: spec constructs it does not fully
support, where it falls back to a documented, narrower behavior instead of
guessing. These are NOT bugs, they are deliberate, documented limitations (see
the root `CLAUDE.md` "Honest residuals" section and `ROADMAP.md`). The risk is
that a future change silently makes one behave WORSE than documented, e.g. a
crash, a 500, or silently accepting clearly-invalid input where the doc implies
rejection. These tests encode the CURRENT documented behavior over real HTTP so
any such regression fails loudly. None of them weaken the contract: where a
residual ever behaves worse than its promise, the test is left at the promised
behavior and parked `test.fixme` with a reported finding (none were needed here,
all five behave as documented).

Each pin adds a minimal `/lab/*` operation, a concrete echo in `LabController`,
and a strict raw-HTTP test. The generate-time warnings below were captured
verbatim from `./generate.sh`.

| Construct | Documented behavior | What the test proves | generate.sh warning |
|---|---|---|---|
| `GET /lab/styles`: a `deepObject` exploded object query param (`filter`) + a `pipeDelimited` array query param (`ids`), alongside a normal `page` int param | Non-standard query styles are SKIPPED with a warning and never appear in the generated query Data class (no validation); the supported `page` param is still validated | The op 200s with arbitrary/garbage `filter`/`ids` values (they do not gate the request), and `page` is still validated (min:1/max:50 reject out of range). The generated `LabStylesQueryData` carries ONLY `page`. | `query parameter "filter" was skipped: style "deepObject" is not supported yet.` and `query parameter "ids" was skipped: style "pipeDelimited" serializes the array into a single delimited value, which the generated array rules cannot validate.` |
| `GET /lab/cookie`: a required `in: cookie` param (`session_hint`) | The cookie param is DROPPED with a warning and never typed or validated; the abstract method takes no argument for it | The op 200s with NO cookie at all (despite `required: true`) AND with a garbage cookie value that violates the spec pattern, so it is provably never validated. | `cookie parameter(s) "session_hint" are not generated (cookie parameters are not supported yet).` |
| `POST /lab/int64`: an `integer` `format: int64` field (`ledger`) with `minimum: 1`, `maximum: 9000000000000000000` | int64 bounds degrade gracefully (no crash) | A normal in-range value round-trips 201. On this 64-bit platform BOTH bounds fit `PHP_INT_MAX`, so the generator emitted REAL `min:`/`max:` rules (not docblock-only): the test documents that these particular bounds ARE enforced here (a value above the max 422s, below the min 422s). | none (the bounds are emitted as ordinary rules) |
| `POST /lab/loose-union`: a `oneOf` of two OBJECT schemas with NO discriminator (`payload`) (#31) | Typed `mixed`, presence-only; NOT hydrated into a specific variant | A body matching EITHER variant is accepted and round-trips untouched, AND a payload matching NEITHER variant's required field is STILL accepted (no variant-specific validation). The only rule is presence: a missing `payload` 422s. | `Schema "LabLooseUnion": a oneOf/anyOf member is not a plain scalar or a $ref to a generated Data class; the union degrades to mixed with presence-only validation.` |
| `GET /lab/dual-status`: declares BOTH `200` and `202`, where the concrete controller explicitly returns 202 (#64) | The selected success status is the smallest 2xx (200); only an exactly declared NON-200 selected success gets `RespondsWithStatus`, so a controller-set 202 passes through untouched | The controller-set 202 stays 202, not clobbered to 200. The route carries NO `RespondsWithStatus` middleware; the 200 declares no body (typed `JsonResponse`), so the controller is free to set its own status and the generator does not rewrite it. | none (no-content 200 keeps the JsonResponse default silently) |

## CORS

`config/cors.php` is fully permissive (`paths: api/*`, origins/methods/headers
all `*`), with `X-Total-Count` added to `exposed_headers` so the cross-origin
SPA can read it. This is DEMO ONLY, to let the SPA call the API from any origin.
A real deployment must pin origins and tighten methods/headers.
