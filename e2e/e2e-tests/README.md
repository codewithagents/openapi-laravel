# e2e-tests

Playwright headless-Chrome suite for the openapi-laravel cross-language demo.

## What it proves

The suite drives the SPA at `http://localhost:8080` in headless Chromium and
asserts the full round trip:

```
browser -> SPA -> generated openapi-zod-ts client -> real HTTP -> generated Laravel backend
```

Every assertion maps to a specific contract-first proof:

| Scenario | What is proved |
|---|---|
| Seeded pets load | The generated client GETs and the SPA renders |
| Create valid pet | Generated client POSTs and generated backend stores |
| 422 on empty name | spec-derived `rules()` validation reaches the browser |
| `created_at` in detail | readOnly field is server-set and returned |
| `secret_note` absent from detail | writeOnly field is never returned by backend |
| String `external_id` round-trips | oneOf scalar union preserves the string type |
| Numeric `external_id` round-trips | oneOf scalar union preserves the integer type |
| Explicit `null` for `weight_kg` | nullable number round-trips correctly |
| Attributes map round-trips | additionalProperties map survives the full stack |
| Delete removes from list | Generated client DELETEs and list re-fetches |
| Status filter tabs | findPetsByStatus over real HTTP filters correctly |
| Multipart PNG upload (#75) | generated `UploadFileRequestData` (`UploadedFile` + `file`/`mimetypes` rules) accepts the multipart POST; the photo URL lands on the pet |
| Non-PNG upload 422 (#75) | the generated `mimetypes:image/png` rule rejects a text file |
| Upload 401 / 200 (#77) | the mapped `api-key` middleware on the generated route enforces the `X-API-Key` scheme |
| DELETE -> 204 (#64) | the generated `void` return + `RespondsWithStatus:204` yield an exact 204 with an empty body |
| X-Total-Count header (#114, residual) | CONSUMER-written `TotalCountHeader` middleware sets the spec-declared header; the generator only warns, so this proves consumer glue, not generator support |
| Upload image serving (#75, D) | the recorded `/storage/uploads/<file>` URL serves the bytes (byte-identical to the upload) via the public storage symlink |

The suite also has an API-contract tier: the stateless `/lab/*` endpoints hit
the backend directly (Playwright `request` fixture, no UI) to prove the full
runtime feature matrix in breadth: numeric/string/array constraints, string
formats, enum/const, closed objects, presence/defaults, typed maps (incl. empty
map serializes `{}`), oneOf scalar unions, nested objects + collections, backed
enums, allOf merge, discriminated unions, component `$ref` request/response
bodies (#110/#116), and a non-200 success status. One row is a deliberately
parked `test.fixme`: the findByTags OpenAPI explode repeated-key form, a PHP
runtime residual (PHP collapses repeated query keys to the last value), not a
generator bug. See `../README.md` for the full living coverage table with the
spec construct, generated-vs-consumer breakdown, and proven-vs-residual status.

## Prerequisites

- Docker Desktop running
- Node.js 18+

## One-command run (brings stack up, tests, tears down)

```bash
cd e2e/e2e-tests
npm install
./run.sh
```

Or from the repo root:

```bash
cd e2e/e2e-tests && ./run.sh
```

## Run against an already-running stack

```bash
cd e2e/e2e-tests
./run.sh --no-docker
# or
SKIP_DOCKER=1 ./run.sh
```

## Run only Playwright (stack must already be up)

```bash
cd e2e/e2e-tests
npm install
npx playwright install --with-deps chromium
npx playwright test
```

## Reports

After a run, open `playwright-report/index.html` for the full HTML report.
Traces are collected on the first retry of any failing test.

## Layout

```
e2e-tests/
  playwright.config.ts     Playwright configuration
  run.sh                   Orchestration: up, wait, test, down
  tests/
    petstore.spec.ts       All end-to-end scenarios
    fixtures/
      pixel.png            1x1 PNG used by the multipart upload test
      not-an-image.txt     non-PNG used to prove the mimetypes rule rejects it
  .gitignore
  README.md
```

## Known interop gap: openapi-zod-ts Accept header

The generated openapi-zod-ts client (`e2e/frontend/src/api/client.ts`) does not
add an `Accept: application/json` header to its requests. When a browser sends a
fetch without an explicit Accept header, the browser default is
`text/html,application/xhtml+xml,...`. Laravel's `$request->wantsJson()` then
returns false, and its error path returns an HTML 302 redirect instead of a 422
JSON response. This caused all state-changing requests (POST, PUT, DELETE) to
fail with "Failed to fetch" in the browser.

The fix is two-layered:

1. **Backend (primary, robust)**: `ForceJsonAccept` middleware in
   `e2e/backend/app/Http/Middleware/ForceJsonAccept.php`, applied to the
   generated API route group in `bootstrap/app.php`. It unconditionally sets
   `Accept: application/json` on every inbound API request so that Laravel
   always responds with JSON regardless of what the client advertised.

2. **Frontend (defense in depth)**: `e2e/frontend/src/main.tsx` now passes
   `headers: { Accept: 'application/json' }` to `configureClient`. This
   propagates to every fetch call, which is the correct client-side fix.

This is NOT a bug in the generated Laravel code (`openapi-laravel`). The
generated controllers, routes, and validation rules are correct. It is a gap in
the openapi-zod-ts client generator: that generator should emit
`Accept: application/json` in every request alongside the `Content-Type` header
it already emits. The fix should be applied upstream in openapi-zod-ts.
