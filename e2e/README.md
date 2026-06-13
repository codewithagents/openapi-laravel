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
| path param constraint (#113) | `in: path` integer with `minimum`/`maximum` | an out-of-range path value SHOULD 422 | NOT generated: the path param is typed `int` but the spec min/max is dropped | residual: `.fixme` holding the promised 422; actual today is 200 (flips green when #113 ships) |
| header param (#121 pending) | `in: header` param, currently echoed | the header value is read and echoed (no validation today) | NOT generated: typed/validated request header params are not built yet | pending: echo-only test active; a `.fixme` holds the promised 422 for a bad `^tok-[0-9]{4}$` value and a missing required header (flips green when #121 ships) |
| routes group (#71) | `routes.prefix: v1` + `routes.middleware` | only `/api/v1/*` is reachable (un-prefixed 404s) and the group middleware stamps `X-Route-Group: v1` | generated `Route::group([prefix, middleware])`; consumer writes the marker middleware | proven |
| inline object request body (#76) | inline `application/json` OBJECT body (no `$ref`) | the synthesized class validates (minLength, max, required) and round-trips | generated `<Operation>RequestData` from the inline schema | proven |
| shared inline-object $ref (#110/#116) | two operations referencing ONE `requestBodies` $ref to an inline object schema | both operations validate and round-trip through the SAME class with identical rules | generated single shared `<Component>RequestData` class | proven |
| inline-union discriminator (#38) | inline `oneOf` + `discriminator` (synthesized variant names) | each variant hydrates by `petType`; an unknown value 422s | generated morphable base + synthesized variants | proven |
| allOf-inheritance discriminator (#38) | `discriminator` over `allOf`-inheritance variants | each variant hydrates by `vehicleType`; the variant rule fires post-morph; an unknown value 422s | generated morphable base + inherited variants | proven |
| missing discriminator (residual) | discriminated union, discriminator property ABSENT | a MISSING discriminator SHOULD 422 | the `from()` path throws before validation when the discriminator is absent | residual: `.fixme` holding the promised 422; actual today is 500 (covers both inline-union and allOf-inheritance forms) |
| response union (#116) | success response `oneOf` of two Data classes | each branch returns its own shape correctly; the selector enum is validated | generated union return type | proven |
| anyOf scalar union | `anyOf: [boolean, integer]` | BOTH variants hydrate without coercion (bool stays bool, int stays int) | generated `bool\|int` union type | proven |
| plain-text response (#117/#118) | `text/plain` success response | the runtime serves `text/plain` with the right body | generated base `Response` return + warning; body is consumer-written | proven (honest typing: no Data return) |
| binary download (#117/#118) | `application/octet-stream` success response | the runtime serves octet-stream bytes | generated base `Response` return + warning; body is consumer-written | proven (honest typing) |
| multipart array of binary (#75 edge) | `multipart/form-data` with `photos: array<string/binary>` + an `album` field | two PNG parts + the field round-trip the count; a non-PNG part 422s via the per-file rule | generated `photos.*` `file`/`mimetypes` rules + non-binary field validation | proven |
| tuple prefixItems (#82) | `prefixItems: [string, integer(min:0)]` | a valid tuple round-trips; a wrong-type or below-min value at a position 422s | generated per-position rules (types as `array<int, mixed>`) | proven (per-position validation; the array element TYPE is still `mixed`) |

Every active row above is an assertion. Two of them (the unknown discriminator
and the 202-on-POST status) were first shipped as `test.fixme` holding the
promised assertion because real behavior diverged; the e2e suite surfaced both
as bugs, they were fixed in the generator (#124 / PR #127 and #125 / PR #128),
and the assertions are now active and green. This is the suite working as
intended: strict assertions that encode the promised contract and fail until the
contract actually holds.

Three Pass-4 rows are currently parked as `test.fixme`, each holding the PROMISED
assertion with a loud comment citing the issue, so they flip green the day the
generator catches up (and fail loudly if the parked behavior silently changes):

- path-param min/max validation (#113): out-of-range path value is 200 today,
  promised 422.
- header-param validation (#121 pending): a bad/missing constrained header is 200
  today, promised 422. The active companion test asserts the current echo-only
  reality so the gap is documented, not hidden.
- missing discriminator (residual): an ABSENT discriminator property returns 500
  today (the #124 fix only made an UNKNOWN value a clean 422), promised 422.

These three were re-verified live against this stack and behave exactly as the
parked comments describe.

## CORS

`config/cors.php` is fully permissive (`paths: api/*`, origins/methods/headers
all `*`), with `X-Total-Count` added to `exposed_headers` so the cross-origin
SPA can read it. This is DEMO ONLY, to let the SPA call the API from any origin.
A real deployment must pin origins and tighten methods/headers.
