# e2e/frontend

Contract-first SPA demo. A Vite + React + TypeScript single-page app that consumes
the TypeScript client generated from `e2e/spec/petstore.yaml` via `openapi-zod-ts@1.0.1`.

This is the real-adopter path: the same published package a user would install.

## Stack

- Vite 5 + React 18 + TypeScript (strict mode)
- `openapi-zod-ts@1.0.1` for client generation (devDependency, zero runtime footprint)
- Native `fetch` under the hood (no axios, no extra wrappers)
- CSS only, no UI framework

## Commands

```bash
# Install dependencies
pnpm install

# Regenerate the TypeScript client from the shared spec
pnpm gen

# Start the dev server (default: http://localhost:5173)
pnpm dev

# Type-check + production build (outputs to dist/)
pnpm build
```

## Environment variable

| Variable | Default | Description |
|---|---|---|
| `VITE_API_BASE` | `http://localhost:8088/api` | Base URL for the Laravel backend API |

Set it in a `.env.local` file or as a shell variable before building:

```bash
VITE_API_BASE=http://localhost:8088/api pnpm dev
```

The backend (e2e/backend) serves on port 8088 by default.

## Generated client

`pnpm gen` runs:

```
openapi-zod-ts --input ../spec/petstore.yaml --output src/api
```

Output files in `src/api/` (committed as proof artifact):

| File | Contents |
|---|---|
| `models.ts` | TypeScript types for every schema (Pet, PetWritable, Order, User, ...) |
| `client-config.ts` | `configureClient()` and `ClientConfig` interface |
| `client.ts` | One typed `async function` per operation (addPet, findPetsByStatus, getPetById, deletePet, ...) |
| `index.ts` | Re-exports all of the above |

### Petstore-plus type highlights

The spec extends the standard Petstore with fields that exercise edge cases in both
the PHP generator and the TypeScript generator:

| Field | Spec | Generated TypeScript |
|---|---|---|
| `status` | `enum: [available, pending, sold]` | `"available" \| "pending" \| "sold"` |
| `microchip_id` | `type: string` (snake_case wire name) | `microchip_id?: string` |
| `secret_note` | `writeOnly: true` | present in `PetWritable` only, absent from `Pet` |
| `created_at` | `readOnly: true` | present in `Pet` only, absent from `PetWritable` |
| `weight_kg` | `nullable: true` | `weight_kg?: number \| null` |
| `attributes` | `additionalProperties: {type: string}` | `attributes?: Record<string, string>` |
| `external_id` | `oneOf: [string, integer]` | `external_id?: string \| number` |

`addPet` and `updatePet` accept `PetWritable` (the request shape), which includes
`secret_note` but excludes `created_at`. GET operations return `Pet` (the response shape),
which includes `created_at` but never `secret_note`.

## Data-testid selectors for Playwright

These selectors are stable and intended for the e2e Playwright suite (next milestone):

### Pet list

| Selector | Element |
|---|---|
| `[data-testid="pet-list"]` | The `<table>` containing all pet rows |
| `[data-testid="pet-row"]` | One `<tr>` per pet |
| `[data-testid="pet-id"]` | Pet ID cell |
| `[data-testid="pet-name"]` | Pet name cell |
| `[data-testid="pet-status"]` | Status badge |
| `[data-testid="pet-microchip-id"]` | Microchip ID cell |
| `[data-testid="pet-weight-kg"]` | Weight cell |
| `[data-testid="pet-external-id"]` | External ID cell |
| `[data-testid="view-btn"]` | Opens the detail panel for that pet |
| `[data-testid="delete-btn"]` | Deletes that pet (DELETE /pet/{id}, reflects in list) |
| `[data-testid="status-filter"]` | Filter button group |
| `[data-testid="filter-available"]` | Filter to available pets |
| `[data-testid="filter-pending"]` | Filter to pending pets |
| `[data-testid="filter-sold"]` | Filter to sold pets |
| `[data-testid="empty-state"]` | Shown when no pets match the filter |
| `[data-testid="list-error"]` | API error banner for the list |
| `[data-testid="delete-error"]` | Error banner after a failed delete |

### Create form

| Selector | Element |
|---|---|
| `[data-testid="create-form"]` | The `<form>` element |
| `[data-testid="field-name"]` | Name input |
| `[data-testid="field-status"]` | Status `<select>` |
| `[data-testid="field-photo-url"]` | Photo URL input |
| `[data-testid="field-microchip-id"]` | Microchip ID input |
| `[data-testid="field-weight-kg"]` | Weight input (enter "null" for explicit null) |
| `[data-testid="field-external-id"]` | External ID input (numeric or string) |
| `[data-testid="field-secret-note"]` | Secret note input (writeOnly field) |
| `[data-testid="attribute-key-N"]` | Key input of the Nth attribute pair |
| `[data-testid="attribute-value-N"]` | Value input of the Nth attribute pair |
| `[data-testid="attribute-add"]` | Add another attribute row |
| `[data-testid="attribute-remove-N"]` | Remove the Nth attribute row |
| `[data-testid="submit"]` | Submit button |
| `[data-testid="error-global"]` | Non-field error from the API |
| `[data-testid="error-name"]` | Field error for name (from Laravel 422) |
| `[data-testid="error-status"]` | Field error for status |
| `[data-testid="error-photo-urls"]` | Field error for photoUrls |
| `[data-testid="error-microchip-id"]` | Field error for microchip_id |
| `[data-testid="error-weight-kg"]` | Field error for weight_kg |
| `[data-testid="error-external-id"]` | Field error for external_id |
| `[data-testid="error-secret-note"]` | Field error for secret_note |
| `[data-testid="error-attributes"]` | Field error for attributes |

### Pet detail panel

| Selector | Element |
|---|---|
| `[data-testid="pet-detail"]` | The detail panel container |
| `[data-testid="detail-close"]` | Close the detail panel |
| `[data-testid="detail-id"]` | Pet ID |
| `[data-testid="detail-name"]` | Pet name |
| `[data-testid="detail-status"]` | Status badge |
| `[data-testid="detail-photo-urls"]` | Photo URLs |
| `[data-testid="detail-category"]` | Category name |
| `[data-testid="detail-microchip-id"]` | Microchip ID |
| `[data-testid="detail-weight-kg"]` | Weight (shows "null" for explicit null) |
| `[data-testid="detail-external-id"]` | External ID |
| `[data-testid="detail-attributes"]` | Attributes as key=value pairs |
| `[data-testid="detail-created-at"]` | Created at (readOnly, server-set) |
| `[data-testid="detail-tags"]` | Tags |

## 422 validation error handling

Server-side validation errors from Laravel are returned as:

```json
{
  "message": "The name field is required.",
  "errors": {
    "name": ["The name field is required."],
    "photoUrls": ["The photo urls field is required."]
  }
}
```

The form parses this structure and maps each field key to its first message.
Each field error renders directly below the relevant input with a `data-testid="error-<field>"`.
