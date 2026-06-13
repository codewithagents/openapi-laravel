import { test, expect, type Page } from '@playwright/test';
import path from 'node:path';

// Playwright compiles this spec as CommonJS, so __dirname is available natively.
const PNG_FIXTURE = path.join(__dirname, 'fixtures', 'pixel.png');
const TXT_FIXTURE = path.join(__dirname, 'fixtures', 'not-an-image.txt');

// The backend API base, host-reachable, for tests that assert raw HTTP status
// codes / headers directly (the generated client abstracts those away). The SPA
// itself talks to this same origin from the browser. The /v1 segment is the
// generated route-group prefix (routes.prefix, #71); the server ORIGIN (for
// static assets like uploaded images) strips both /api and /v1.
const API_BASE = process.env['API_BASE'] ?? 'http://localhost:8088/api/v1';
const SERVER_ORIGIN = API_BASE.replace(/\/api(\/v1)?$/, '');
const UPLOAD_API_KEY = 'demo-upload-key';

/**
 * Petstore end-to-end suite.
 *
 * Two tiers:
 *  - UI journeys: drive the SPA at http://localhost:8080 in headless Chromium,
 *    proving browser -> SPA -> generated openapi-zod-ts client -> real HTTP ->
 *    generated Laravel backend (via openapi-laravel).
 *  - API-contract tier (the /lab/* tests below): hit the backend directly via
 *    Playwright's `request` fixture for breadth over the runtime feature matrix
 *    (validation, serialization, composition), no UI involved.
 *
 * The backend seeds two pets on first start (Rex/available, Whiskers/pending).
 * The store is file-backed and persists within a container run, so tests
 * run serially and earlier creates/deletes are visible to later tests.
 *
 * Data-testid reference (read from e2e/frontend/src/components/):
 *   pet-list, pet-row, pet-name, pet-status, pet-microchip-id,
 *   pet-weight-kg, pet-external-id, view-btn, delete-btn,
 *   create-form, field-name, field-status, field-microchip-id,
 *   field-weight-kg, field-external-id, field-secret-note,
 *   attribute-key-N, attribute-value-N, attribute-remove-N, attribute-add, submit,
 *   error-name, error-status, error-global,
 *   pet-detail, detail-id, detail-name, detail-status, detail-created-at,
 *   detail-microchip-id, detail-weight-kg, detail-external-id, detail-attributes,
 *   detail-photo-urls, detail-close,
 *   upload-file-input, upload-caption-input, upload-submit, upload-status, upload-error,
 *   status-filter, filter-available, filter-pending, filter-sold,
 *   list-loading, empty-state, list-error, delete-error
 */

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Navigate to the SPA root and wait until the page is interactive. */
async function openApp(page: Page): Promise<void> {
  await page.goto('/');
  // Wait for the create form to be present: that means React has hydrated.
  await page.waitForSelector('[data-testid="create-form"]', { timeout: 20_000 });
}

/**
 * Wait for the pet list to finish loading and contain at least one row.
 * The list transitions through a loading state before rows appear.
 */
async function waitForPetList(page: Page): Promise<void> {
  // Wait for loading indicator to disappear (if it appeared).
  await page.waitForFunction(
    () => !document.querySelector('[data-testid="list-loading"]'),
    { timeout: 20_000 },
  );
  // Wait for at least one pet row or the empty-state placeholder.
  await Promise.race([
    page.waitForSelector('[data-testid="pet-row"]', { timeout: 15_000 }),
    page.waitForSelector('[data-testid="empty-state"]', { timeout: 15_000 }),
  ]);
}

/**
 * Fill and submit the create-pet form with the given values.
 *
 * photoUrl defaults to a placeholder URL so that photoUrls is never an empty
 * array: the spec marks photoUrls as required and the backend validation rejects
 * an empty array with a 422. Tests that want to test validation failures for
 * other fields can override photoUrl: '' to leave the field blank.
 */
async function fillAndSubmitCreateForm(
  page: Page,
  opts: {
    name: string;
    status?: 'available' | 'pending' | 'sold';
    photoUrl?: string;
    microchipId?: string;
    weightKg?: string;
    externalId?: string;
    secretNote?: string;
    attributeKey?: string;
    attributeValue?: string;
  },
): Promise<void> {
  await page.fill('[data-testid="field-name"]', opts.name);

  if (opts.status !== undefined) {
    await page.selectOption('[data-testid="field-status"]', opts.status);
  }

  // Always fill photo URL with a sensible default so photoUrls is non-empty.
  // The spec requires at least one photo URL; an empty array triggers 422.
  const photoUrl = opts.photoUrl !== undefined ? opts.photoUrl : 'https://example.com/photo.jpg';
  await page.fill('[data-testid="field-photo-url"]', photoUrl);

  if (opts.microchipId !== undefined) {
    await page.fill('[data-testid="field-microchip-id"]', opts.microchipId);
  }
  if (opts.weightKg !== undefined) {
    await page.fill('[data-testid="field-weight-kg"]', opts.weightKg);
  }
  if (opts.externalId !== undefined) {
    await page.fill('[data-testid="field-external-id"]', opts.externalId);
  }
  if (opts.secretNote !== undefined) {
    await page.fill('[data-testid="field-secret-note"]', opts.secretNote);
  }
  if (opts.attributeKey !== undefined && opts.attributeValue !== undefined) {
    await page.fill('[data-testid="attribute-key-0"]', opts.attributeKey);
    await page.fill('[data-testid="attribute-value-0"]', opts.attributeValue);
  }

  await page.click('[data-testid="submit"]');
}

// ---------------------------------------------------------------------------
// Scenario 1: Seeded pets load in the list
// ---------------------------------------------------------------------------

test('loads the SPA and shows seeded pets in the list', async ({ page }) => {
  await openApp(page);

  // The default status filter is 'available'. Rex is seeded as available.
  await waitForPetList(page);

  // The pet-list table should be visible.
  await expect(page.locator('[data-testid="pet-list"]')).toBeVisible();

  // At least one row should appear. Rex is always in the available list
  // (seeded at container start). Other rows may also exist from earlier test
  // runs within the same container lifetime, so we use >=1 rather than ==1.
  const rows = page.locator('[data-testid="pet-row"]');
  await expect(rows.first()).toBeVisible();

  // Rex must be present somewhere in the available list.
  const rexRow = page.locator('[data-testid="pet-row"]', {
    has: page.locator('[data-testid="pet-name"]:text("Rex")'),
  });
  await expect(rexRow).toBeVisible();
  await expect(rexRow.locator('[data-testid="pet-status"]')).toHaveText('available');
  await expect(rexRow.locator('[data-testid="pet-microchip-id"]')).toHaveText('chip-rex-0001');
  await expect(rexRow.locator('[data-testid="pet-weight-kg"]')).toHaveText('12.5');

  // Rex has a string external_id.
  await expect(rexRow.locator('[data-testid="pet-external-id"]')).toHaveText('ext-rex-legacy');
});

test('shows the pending filter and renders Whiskers with numeric external_id', async ({ page }) => {
  await openApp(page);

  // Switch to pending filter.
  await page.click('[data-testid="filter-pending"]');
  await waitForPetList(page);

  // Whiskers must be present in the pending list (seeded at container start).
  const rows = page.locator('[data-testid="pet-row"]');
  await expect(rows.first()).toBeVisible();

  const whiskersRow = page.locator('[data-testid="pet-row"]', {
    has: page.locator('[data-testid="pet-name"]:text("Whiskers")'),
  });
  await expect(whiskersRow).toBeVisible();
  await expect(whiskersRow.locator('[data-testid="pet-status"]')).toHaveText('pending');

  // Whiskers has weight_kg null.
  await expect(whiskersRow.locator('[data-testid="pet-weight-kg"]')).toHaveText('null');

  // Whiskers has a numeric external_id (the oneOf scalar union case).
  await expect(whiskersRow.locator('[data-testid="pet-external-id"]')).toHaveText('559900');
});

// ---------------------------------------------------------------------------
// Scenario 2: Create a valid pet and see it appear in the list
// ---------------------------------------------------------------------------

test('creates a valid pet and it appears in the list', async ({ page }) => {
  await openApp(page);
  await waitForPetList(page);

  const uniqueName = `E2E-Dog-${Date.now()}`;

  await fillAndSubmitCreateForm(page, {
    name: uniqueName,
    status: 'available',
    microchipId: 'chip-e2e-999',
    weightKg: '7.3',
    externalId: 'ext-e2e-abc',
    secretNote: 'do not expose this',
    attributeKey: 'color',
    attributeValue: 'black',
  });

  // After submit the form resets and the list refreshes.
  // Wait for the new pet row to appear.
  await page.waitForFunction(
    (name: string) => {
      const cells = Array.from(document.querySelectorAll('[data-testid="pet-name"]'));
      return cells.some((el) => el.textContent === name);
    },
    uniqueName,
    { timeout: 20_000 },
  );

  // Find the row that has our new pet.
  const newRow = page.locator('[data-testid="pet-row"]', {
    has: page.locator(`[data-testid="pet-name"]:text("${uniqueName}")`),
  });
  await expect(newRow).toBeVisible();
  await expect(newRow.locator('[data-testid="pet-status"]')).toHaveText('available');
  await expect(newRow.locator('[data-testid="pet-microchip-id"]')).toHaveText('chip-e2e-999');
  await expect(newRow.locator('[data-testid="pet-weight-kg"]')).toHaveText('7.3');
  // String external_id round-trips as a string.
  await expect(newRow.locator('[data-testid="pet-external-id"]')).toHaveText('ext-e2e-abc');
});

test('creates a pet with a numeric external_id and it round-trips as a number', async ({ page }) => {
  await openApp(page);
  await waitForPetList(page);

  const uniqueName = `E2E-NumId-${Date.now()}`;

  await fillAndSubmitCreateForm(page, {
    name: uniqueName,
    status: 'available',
    externalId: '42099',
  });

  await page.waitForFunction(
    (name: string) => {
      const cells = Array.from(document.querySelectorAll('[data-testid="pet-name"]'));
      return cells.some((el) => el.textContent === name);
    },
    uniqueName,
    { timeout: 20_000 },
  );

  const newRow = page.locator('[data-testid="pet-row"]', {
    has: page.locator(`[data-testid="pet-name"]:text("${uniqueName}")`),
  });
  // Numeric external_id round-trips as a number.
  await expect(newRow.locator('[data-testid="pet-external-id"]')).toHaveText('42099');
});

test('creates a pet with explicit null weight_kg', async ({ page }) => {
  await openApp(page);
  await waitForPetList(page);

  const uniqueName = `E2E-NullWeight-${Date.now()}`;

  await fillAndSubmitCreateForm(page, {
    name: uniqueName,
    status: 'available',
    weightKg: 'null',
  });

  await page.waitForFunction(
    (name: string) => {
      const cells = Array.from(document.querySelectorAll('[data-testid="pet-name"]'));
      return cells.some((el) => el.textContent === name);
    },
    uniqueName,
    { timeout: 20_000 },
  );

  const newRow = page.locator('[data-testid="pet-row"]', {
    has: page.locator(`[data-testid="pet-name"]:text("${uniqueName}")`),
  });
  await expect(newRow.locator('[data-testid="pet-weight-kg"]')).toHaveText('null');
});

// ---------------------------------------------------------------------------
// Scenario 3: Invalid submission triggers 422 validation errors
// ---------------------------------------------------------------------------

test('submitting an empty name shows a 422 validation error', async ({ page }) => {
  await openApp(page);

  // Leave name blank, submit.
  await page.click('[data-testid="submit"]');

  // The error-name element should appear with a non-empty validation message.
  const errorName = page.locator('[data-testid="error-name"]');
  await expect(errorName).toBeVisible({ timeout: 10_000 });
  // The message text is the validator's, surfaced through the generated client.
  // Assert it is non-empty and names the offending field (Laravel's default
  // required message contains the attribute name 'name').
  await expect(errorName).not.toBeEmpty();
  await expect(errorName).toContainText(/name/i);
});

// Raw-HTTP companion: prove the create endpoint itself enforces the required
// 'name' with an exact 422 and a Laravel-shaped validation error body, so the
// UI assertion above is grounded in the generated rules(), not UI glue.
test('POST /pet with a missing name 422s with a name-keyed error bag', async ({ request }) => {
  const res = await request.post(`${API_BASE}/pet`, {
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    data: { photoUrls: ['https://example.com/x.png'], status: 'available' },
  });
  expect(res.status()).toBe(422);
  const body = await res.json();
  // Laravel's ValidationException response is { message, errors: { field: [...] } }.
  expect(body).toHaveProperty('errors');
  expect(body.errors).toHaveProperty('name');
  expect(Array.isArray(body.errors.name)).toBe(true);
  expect(body.errors.name.length).toBeGreaterThan(0);
});

// Raw-HTTP: an out-of-enum status is rejected by the generated enum rule.
test('POST /pet with an out-of-enum status 422s', async ({ request }) => {
  const res = await request.post(`${API_BASE}/pet`, {
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    data: { name: `E2E-BadStatus-${Date.now()}`, photoUrls: ['https://example.com/x.png'], status: 'flying' },
  });
  expect(res.status()).toBe(422);
  const body = await res.json();
  expect(body.errors).toHaveProperty('status');
});

// ---------------------------------------------------------------------------
// Scenario 4: Pet detail panel - readOnly created_at present, writeOnly absent
// ---------------------------------------------------------------------------

test('opening a pet detail shows created_at (readOnly) and no secret_note field', async ({ page }) => {
  await openApp(page);
  await waitForPetList(page);

  // Click View on Rex (the first row, available filter is default).
  const firstRow = page.locator('[data-testid="pet-row"]').first();
  await firstRow.locator('[data-testid="view-btn"]').click();

  // The detail panel should appear.
  await expect(page.locator('[data-testid="pet-detail"]')).toBeVisible({ timeout: 10_000 });

  // created_at must be present and non-empty (server-set, readOnly).
  const createdAt = page.locator('[data-testid="detail-created-at"]');
  await expect(createdAt).toBeVisible();
  const createdAtText = await createdAt.textContent();
  expect(createdAtText).not.toBeNull();
  expect(createdAtText).not.toBe('none');
  expect(createdAtText!.length).toBeGreaterThan(0);

  // The panel shows name and status.
  await expect(page.locator('[data-testid="detail-name"]')).toHaveText('Rex');
  await expect(page.locator('[data-testid="detail-status"]')).toHaveText('available');

  // secret_note must NOT appear as a data row in the panel.
  // The component omits it entirely (writeOnly: the server never returns it).
  // Note: the panel DOES contain an informational footer mentioning "secret_note"
  // by name, so we cannot check for the string itself. We check that there is no
  // data-testid="detail-secret-note" element and that no value leaks through.
  await expect(page.locator('[data-testid="detail-secret-note"]')).not.toBeAttached();
  // Also verify no field with the word "Secret Note" as a label appears.
  await expect(page.locator('[data-testid="pet-detail"]').getByText('Secret Note')).not.toBeAttached();

  // Close the panel.
  await page.click('[data-testid="detail-close"]');
  await expect(page.locator('[data-testid="pet-detail"]')).not.toBeVisible();
});

test('detail panel for a created pet has created_at and shows attributes', async ({ page }) => {
  await openApp(page);
  await waitForPetList(page);

  const uniqueName = `E2E-Detail-${Date.now()}`;

  await fillAndSubmitCreateForm(page, {
    name: uniqueName,
    status: 'available',
    secretNote: 'this must not appear',
    attributeKey: 'env',
    attributeValue: 'test',
  });

  // Wait for the new row to appear.
  await page.waitForFunction(
    (name: string) => {
      const cells = Array.from(document.querySelectorAll('[data-testid="pet-name"]'));
      return cells.some((el) => el.textContent === name);
    },
    uniqueName,
    { timeout: 20_000 },
  );

  // Click View on the new pet.
  const newRow = page.locator('[data-testid="pet-row"]', {
    has: page.locator(`[data-testid="pet-name"]:text("${uniqueName}")`),
  });
  await newRow.locator('[data-testid="view-btn"]').click();

  await expect(page.locator('[data-testid="pet-detail"]')).toBeVisible({ timeout: 10_000 });

  // created_at is server-set (readOnly) and must be present.
  const createdAt = await page.locator('[data-testid="detail-created-at"]').textContent();
  expect(createdAt).not.toBe('none');
  expect(createdAt).not.toBeNull();
  expect(createdAt!.length).toBeGreaterThan(5);

  // secret_note value must not leak through. The server never returns it
  // (writeOnly), so the value we sent ('this must not appear') cannot be
  // in the panel. There is no detail-secret-note data-testid in the panel.
  await expect(page.locator('[data-testid="detail-secret-note"]')).not.toBeAttached();
  const panelHtml = await page.locator('[data-testid="pet-detail"]').innerHTML();
  expect(panelHtml).not.toContain('this must not appear');

  // Attributes must round-trip.
  const attrs = await page.locator('[data-testid="detail-attributes"]').textContent();
  expect(attrs).toContain('env=test');
});

// ---------------------------------------------------------------------------
// Scenario 5: oneOf external_id - string and number variants both render
// ---------------------------------------------------------------------------

test('string external_id renders as a string in the detail panel', async ({ page }) => {
  await openApp(page);
  await waitForPetList(page);

  // Rex has externalId = 'ext-rex-legacy' (string).
  const firstRow = page.locator('[data-testid="pet-row"]').first();
  await firstRow.locator('[data-testid="view-btn"]').click();

  await expect(page.locator('[data-testid="pet-detail"]')).toBeVisible();
  const extId = await page.locator('[data-testid="detail-external-id"]').textContent();
  expect(extId).toBe('ext-rex-legacy');
  await page.click('[data-testid="detail-close"]');
});

test('numeric external_id renders as a number in the detail panel', async ({ page }) => {
  await openApp(page);

  // Switch to pending to see Whiskers (externalId = 559900 integer).
  await page.click('[data-testid="filter-pending"]');
  await waitForPetList(page);

  const firstRow = page.locator('[data-testid="pet-row"]').first();
  await firstRow.locator('[data-testid="view-btn"]').click();

  await expect(page.locator('[data-testid="pet-detail"]')).toBeVisible();
  const extId = await page.locator('[data-testid="detail-external-id"]').textContent();
  expect(extId).toBe('559900');
  await page.click('[data-testid="detail-close"]');
});

// ---------------------------------------------------------------------------
// Scenario 6: Delete a pet and it disappears from the list
// ---------------------------------------------------------------------------

test('deleting a pet removes it from the list', async ({ page }) => {
  await openApp(page);
  await waitForPetList(page);

  // First create a throwaway pet so we do not destroy seeded data.
  const uniqueName = `E2E-Delete-${Date.now()}`;

  await fillAndSubmitCreateForm(page, {
    name: uniqueName,
    status: 'available',
  });

  await page.waitForFunction(
    (name: string) => {
      const cells = Array.from(document.querySelectorAll('[data-testid="pet-name"]'));
      return cells.some((el) => el.textContent === name);
    },
    uniqueName,
    { timeout: 20_000 },
  );

  // Count rows before delete.
  const rowsBefore = await page.locator('[data-testid="pet-row"]').count();
  expect(rowsBefore).toBeGreaterThan(0);

  // Delete the newly created pet.
  const targetRow = page.locator('[data-testid="pet-row"]', {
    has: page.locator(`[data-testid="pet-name"]:text("${uniqueName}")`),
  });
  await targetRow.locator('[data-testid="delete-btn"]').click();

  // The row should disappear.
  await page.waitForFunction(
    (name: string) => {
      const cells = Array.from(document.querySelectorAll('[data-testid="pet-name"]'));
      return !cells.some((el) => el.textContent === name);
    },
    uniqueName,
    { timeout: 15_000 },
  );

  // Count rows after delete: one fewer.
  const rowsAfter = await page.locator('[data-testid="pet-row"]').count();
  expect(rowsAfter).toBe(rowsBefore - 1);
});

// ---------------------------------------------------------------------------
// Scenario 7: Status filter navigation
// ---------------------------------------------------------------------------

test('status filter switches between available, pending, and sold tabs', async ({ page }) => {
  await openApp(page);

  // Default: available - Rex should be visible.
  await waitForPetList(page);
  await expect(page.locator('[data-testid="filter-available"]')).toBeVisible();
  const availableRows = await page.locator('[data-testid="pet-row"]').count();
  expect(availableRows).toBeGreaterThanOrEqual(1);

  // Pending: Whiskers should be visible.
  await page.click('[data-testid="filter-pending"]');
  await waitForPetList(page);
  const pendingRows = await page.locator('[data-testid="pet-row"]').count();
  expect(pendingRows).toBeGreaterThanOrEqual(1);

  // Sold: no seeded pets with sold status -> empty state.
  await page.click('[data-testid="filter-sold"]');
  await waitForPetList(page);
  await expect(page.locator('[data-testid="empty-state"]')).toBeVisible();
});

// ---------------------------------------------------------------------------
// Helper: create a pet via the SPA and open its detail panel. Returns the
// unique name so callers can re-locate it.
// ---------------------------------------------------------------------------

async function createPetAndOpenDetail(page: Page, prefix: string): Promise<string> {
  await openApp(page);
  await waitForPetList(page);

  const uniqueName = `${prefix}-${Date.now()}`;
  await fillAndSubmitCreateForm(page, { name: uniqueName, status: 'available' });

  await page.waitForFunction(
    (name: string) => {
      const cells = Array.from(document.querySelectorAll('[data-testid="pet-name"]'));
      return cells.some((el) => el.textContent === name);
    },
    uniqueName,
    { timeout: 20_000 },
  );

  const row = page.locator('[data-testid="pet-row"]', {
    has: page.locator(`[data-testid="pet-name"]:text("${uniqueName}")`),
  });
  await row.locator('[data-testid="view-btn"]').click();
  await expect(page.locator('[data-testid="pet-detail"]')).toBeVisible({ timeout: 10_000 });

  return uniqueName;
}

// ---------------------------------------------------------------------------
// Scenario 8: Multipart photo upload (#75)
//
// What this proves end to end:
//   - The generator changed the octet-stream body to a multipart/form-data
//     OBJECT body and synthesized UploadFileRequestData with an UploadedFile
//     `image` field plus the 'file' + 'mimetypes:image/png' validation rules.
//   - The generated openapi-zod-ts client builds the FormData and posts it.
//   - The concrete controller stores the file and appends its URL to the pet's
//     photoUrls, which the detail panel reflects.
// ---------------------------------------------------------------------------

test('uploads a PNG via the generated multipart client and the photo appears on the pet', async ({ page }) => {
  await createPetAndOpenDetail(page, 'E2E-Upload');

  // The pet starts with exactly one photo URL (the create-form default
  // 'https://example.com/photo.jpg'), and no /storage/uploads/ URL yet.
  const photoUrls = page.locator('[data-testid="detail-photo-urls"]');
  await expect(photoUrls).toContainText('https://example.com/photo.jpg');
  await expect(photoUrls).not.toContainText('/storage/uploads/');

  // Attach the tiny PNG fixture and an optional caption, then upload.
  await page.setInputFiles('[data-testid="upload-file-input"]', PNG_FIXTURE);
  await page.fill('[data-testid="upload-caption-input"]', 'profile shot');
  await page.click('[data-testid="upload-submit"]');

  // The upload status reflects the backend's ApiResponse.message, which echoes
  // the stored photo URL and the caption (proving the multipart parts arrived).
  const status = page.locator('[data-testid="upload-status"]');
  await expect(status).toBeVisible({ timeout: 10_000 });
  const statusText = await status.textContent();
  expect(statusText).toContain('Image uploaded');
  expect(statusText).toContain('/storage/uploads/');
  expect(statusText).toContain('caption: profile shot');

  // After upload the panel refreshes; the new photo URL is now listed.
  await expect(page.locator('[data-testid="detail-photo-urls"]')).toContainText('/storage/uploads/', {
    timeout: 10_000,
  });
});

test('rejects a non-PNG upload with a 422 from the generated mimetypes rule', async ({ page }) => {
  await createPetAndOpenDetail(page, 'E2E-UploadBad');

  // The generated rule is 'mimetypes:image/png'. A text file must be rejected
  // by the Laravel validator before the controller body runs.
  await page.setInputFiles('[data-testid="upload-file-input"]', TXT_FIXTURE);
  await page.click('[data-testid="upload-submit"]');

  const error = page.locator('[data-testid="upload-error"]');
  await expect(error).toBeVisible({ timeout: 10_000 });
  await expect(error).toContainText('422');
});

// Raw-HTTP companion for the multipart rules (#75): a non-PNG part 422s on the
// generated mimetypes rule, and a missing required 'image' part 422s on the
// generated file rule. Both pass the api-key middleware first.
test('multipart uploadImage: wrong mime type and a missing required part both 422', async ({ request }) => {
  const fs = require('node:fs') as typeof import('node:fs');
  const txt = fs.readFileSync(TXT_FIXTURE);

  // Wrong content type for the 'image' part: mimetypes:image/png rejects it.
  const wrongMime = await request.post(`${API_BASE}/pet/1/uploadImage`, {
    headers: { Accept: 'application/json', 'X-API-Key': UPLOAD_API_KEY },
    multipart: { image: { name: 'not-an-image.txt', mimeType: 'text/plain', buffer: txt } },
  });
  expect(wrongMime.status()).toBe(422);
  expect((await wrongMime.json()).errors).toHaveProperty('image');

  // Missing the required 'image' part entirely: the file rule rejects it. We
  // send only the optional caption so the body is a well-formed multipart.
  const missing = await request.post(`${API_BASE}/pet/1/uploadImage`, {
    headers: { Accept: 'application/json', 'X-API-Key': UPLOAD_API_KEY },
    multipart: { caption: 'no file here' },
  });
  expect(missing.status()).toBe(422);
  expect((await missing.json()).errors).toHaveProperty('image');
});

// ---------------------------------------------------------------------------
// Scenario 9: Security middleware (#77)
//
// The spec marks uploadImage as requiring the pet_upload_key apiKey scheme
// (header X-API-Key). config/openapi-laravel.php maps that scheme to the
// 'api-key' middleware, so the generated route carries it. The consumer-written
// ApiKey middleware enforces a fixed demo key. We assert raw HTTP directly so
// the status codes are unambiguous.
// ---------------------------------------------------------------------------

test('upload is rejected with 401 when the API key is missing, and accepted with it', async ({ page, request }) => {
  // Seed pet 1 (Rex) always exists. Build the multipart body for both calls.
  const fs = require('node:fs') as typeof import('node:fs');
  const png = fs.readFileSync(PNG_FIXTURE);
  const multipart = {
    image: { name: 'pixel.png', mimeType: 'image/png', buffer: png },
    caption: 'auth check',
  };

  // Without the X-API-Key header: the generated route's api-key middleware 401s.
  const noKey = await request.post(`${API_BASE}/pet/1/uploadImage`, {
    multipart,
    headers: { Accept: 'application/json' },
  });
  expect(noKey.status()).toBe(401);

  // With the correct key: the request passes the middleware and succeeds.
  // laravel-data serializes a Data object returned from a POST as 201 Created,
  // so the upload (a POST returning ApiResponseData) responds 201.
  const withKey = await request.post(`${API_BASE}/pet/1/uploadImage`, {
    multipart,
    headers: { Accept: 'application/json', 'X-API-Key': UPLOAD_API_KEY },
  });
  expect(withKey.status()).toBe(201);
  // ApiResponseData round-trips with the spec-declared fields and the echoed
  // message, proving the multipart parts (image + caption) arrived intact.
  const bodyJson = await withKey.json();
  expect(bodyJson.code).toBe(200);
  expect(bodyJson.type).toBe('success');
  expect(bodyJson.message).toContain('Image uploaded for pet 1');
  expect(bodyJson.message).toContain('caption: auth check');

  // A WRONG key is rejected too (not just a missing one).
  const wrongKey = await request.post(`${API_BASE}/pet/1/uploadImage`, {
    multipart,
    headers: { Accept: 'application/json', 'X-API-Key': 'not-the-key' },
  });
  expect(wrongKey.status()).toBe(401);

  // And the same X-API-Key path works through the SPA (the generated client
  // forwards the per-call apiKey config as the X-API-Key header). Assert the
  // status text actually reflects a successful upload, not just visibility.
  await createPetAndOpenDetail(page, 'E2E-UploadAuth');
  await page.setInputFiles('[data-testid="upload-file-input"]', PNG_FIXTURE);
  await page.click('[data-testid="upload-submit"]');
  const status = page.locator('[data-testid="upload-status"]');
  await expect(status).toBeVisible({ timeout: 10_000 });
  await expect(status).toContainText('Image uploaded');
  await expect(status).toContainText('/storage/uploads/');
});

// ---------------------------------------------------------------------------
// Scenario 10: 204 No Content on DELETE (#64)
//
// The spec declares 204 for deletePet, so the generator types the abstract
// destroy() void and stamps RespondsWithStatus:204 on the route. We assert the
// raw status code is exactly 204 with an empty body (the row-disappears path is
// already covered indirectly in Scenario 6).
// ---------------------------------------------------------------------------

test('DELETE pet returns a 204 No Content with an empty body', async ({ request }) => {
  // Create a throwaway pet through the API, then delete it and inspect the
  // raw response.
  const created = await request.post(`${API_BASE}/pet`, {
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    data: { name: `E2E-204-${Date.now()}`, photoUrls: ['https://example.com/x.png'], status: 'available' },
  });
  expect(created.status()).toBe(201);
  const createdPet = await created.json();
  const id = createdPet.id;

  const del = await request.delete(`${API_BASE}/pet/${id}`, {
    headers: { Accept: 'application/json' },
  });
  expect(del.status()).toBe(204);
  expect((await del.body()).length).toBe(0);

  // Deleting it again (now gone) is a 404, proving the 204 was a real delete and
  // the not-found path is distinct from the success path.
  const again = await request.delete(`${API_BASE}/pet/${id}`, {
    headers: { Accept: 'application/json' },
  });
  expect(again.status()).toBe(404);
});

// ---------------------------------------------------------------------------
// Scenario 11: X-Total-Count response header (#114, a DOCUMENTED RESIDUAL)
//
// IMPORTANT: this does NOT prove the generator emits response-header handling.
// The generator only WARNS about the spec's declared X-Total-Count header and
// generates nothing for it. The header is set by hand-written consumer glue
// (App\Http\Middleware\TotalCountHeader). This test proves that consumer glue
// works, and documents the seam between generated and hand-written code.
// ---------------------------------------------------------------------------

test('findByStatus carries the consumer-set X-Total-Count header (documented residual)', async ({ request }) => {
  const res = await request.get(`${API_BASE}/pet/findByStatus?status=available`, {
    headers: { Accept: 'application/json' },
  });
  expect(res.status()).toBe(200);

  const total = res.headers()['x-total-count'];
  expect(total).toBeDefined();

  const body = await res.json();
  expect(Array.isArray(body)).toBe(true);
  // The header count matches the number of items actually returned.
  expect(Number(total)).toBe(body.length);
});

// ---------------------------------------------------------------------------
// Scenario 12: Pet read/write split + serialization seams over raw HTTP.
//
// The UI tier proves the readOnly/writeOnly split through the SPA. These raw
// HTTP tests pin the SAME contract directly at the wire, with explicit negative
// assertions: a writeOnly field is genuinely ABSENT from the read response, a
// readOnly field sent by a client is IGNORED (server value wins), the snake_case
// MapName round-trips both directions, and the attributes map + oneOf external_id
// hydrate without coercion.
// ---------------------------------------------------------------------------

test('POST /pet read/write split: writeOnly absent, readOnly server-set, MapName round-trips', async ({ request }) => {
  const name = `E2E-RW-${Date.now()}`;
  const res = await request.post(`${API_BASE}/pet`, {
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    data: {
      name,
      photoUrls: ['https://example.com/rw.png'],
      status: 'available',
      // snake_case wire name maps to camelCase microchipId on the Data class.
      microchip_id: 'chip-rw-7',
      // writeOnly: accepted on write, must NEVER appear in the read response.
      secret_note: 'TOP-SECRET-MUST-NOT-LEAK',
      // readOnly: a client-sent value must be ignored; the server sets its own.
      created_at: '1999-01-01T00:00:00+00:00',
      weight_kg: 4.25,
      external_id: 'ext-rw-legacy',
    },
  });
  expect(res.status()).toBe(201);
  const pet = await res.json();

  // MapName: the snake_case wire field is echoed back as snake_case.
  expect(pet.microchip_id).toBe('chip-rw-7');
  expect(pet.weight_kg).toBe(4.25);
  expect(pet.name).toBe(name);

  // readOnly created_at: present, server-set, and NOT the client-sent 1999 value.
  expect(pet.created_at).toBeDefined();
  expect(pet.created_at).not.toBe('1999-01-01T00:00:00+00:00');

  // writeOnly secret_note: genuinely absent from the read response, by key and
  // by value. Check both the parsed object and the raw text so a leak anywhere
  // in the payload is caught.
  expect(pet).not.toHaveProperty('secret_note');
  const raw = await request.get(`${API_BASE}/pet/${pet.id}`, { headers: { Accept: 'application/json' } });
  expect(raw.status()).toBe(200);
  const rawText = await raw.text();
  expect(rawText).not.toContain('secret_note');
  expect(rawText).not.toContain('TOP-SECRET-MUST-NOT-LEAK');

  // clean up the throwaway pet.
  expect((await request.delete(`${API_BASE}/pet/${pet.id}`, { headers: { Accept: 'application/json' } })).status()).toBe(204);
});

test('POST /pet attributes map round-trips intact, and an empty map serializes as {} not []', async ({ request }) => {
  // Non-empty map round-trips key-for-key.
  const withAttrs = await request.post(`${API_BASE}/pet`, {
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    data: {
      name: `E2E-Attrs-${Date.now()}`,
      photoUrls: ['https://example.com/a.png'],
      status: 'available',
      attributes: { color: 'black', size: 'large' },
    },
  });
  expect(withAttrs.status()).toBe(201);
  const petA = await withAttrs.json();
  expect(petA.attributes).toEqual({ color: 'black', size: 'large' });
  expect((await request.delete(`${API_BASE}/pet/${petA.id}`, { headers: { Accept: 'application/json' } })).status()).toBe(204);

  // Empty map must serialize as a JSON object {}, never an array []. Check raw
  // text so the distinction is unambiguous (the MapObjectTransformer forces it).
  const empty = await request.post(`${API_BASE}/pet`, {
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    data: {
      name: `E2E-EmptyAttrs-${Date.now()}`,
      photoUrls: ['https://example.com/e.png'],
      status: 'available',
      attributes: {},
    },
  });
  expect(empty.status()).toBe(201);
  const emptyText = await empty.text();
  expect(emptyText).toContain('"attributes":{}');
  expect(emptyText).not.toContain('"attributes":[]');
  const petE = await empty.json();
  expect((await request.delete(`${API_BASE}/pet/${petE.id}`, { headers: { Accept: 'application/json' } })).status()).toBe(204);
});

test('POST /pet oneOf external_id hydrates string and integer variants without coercion', async ({ request }) => {
  const asString = await request.post(`${API_BASE}/pet`, {
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    data: { name: `E2E-ExtStr-${Date.now()}`, photoUrls: ['https://example.com/s.png'], status: 'available', external_id: 'abc-123' },
  });
  expect(asString.status()).toBe(201);
  const petS = await asString.json();
  expect(petS.external_id).toBe('abc-123');
  expect(typeof petS.external_id).toBe('string');
  expect((await request.delete(`${API_BASE}/pet/${petS.id}`, { headers: { Accept: 'application/json' } })).status()).toBe(204);

  const asInt = await request.post(`${API_BASE}/pet`, {
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    data: { name: `E2E-ExtInt-${Date.now()}`, photoUrls: ['https://example.com/i.png'], status: 'available', external_id: 778899 },
  });
  expect(asInt.status()).toBe(201);
  const petI = await asInt.json();
  expect(petI.external_id).toBe(778899); // stays an integer, not "778899"
  expect(typeof petI.external_id).toBe('number');
  expect((await request.delete(`${API_BASE}/pet/${petI.id}`, { headers: { Accept: 'application/json' } })).status()).toBe(204);
});

test('GET /pet/{id} for a missing pet returns 404', async ({ request }) => {
  const res = await request.get(`${API_BASE}/pet/99999999`, { headers: { Accept: 'application/json' } });
  expect(res.status()).toBe(404);
});

test('DELETE /pet/{id} for a missing pet returns 404', async ({ request }) => {
  const res = await request.delete(`${API_BASE}/pet/99999999`, { headers: { Accept: 'application/json' } });
  expect(res.status()).toBe(404);
});

// ---------------------------------------------------------------------------
// Scenario 13: findByStatus query parameter (the generated query Data class).
//
// The status query param is a required enum with a spec default of 'available'.
// A valid value filters; an out-of-enum value 422s on the generated rule.
// ---------------------------------------------------------------------------

test('GET /pet/findByStatus filters by a valid status and rejects an out-of-enum status', async ({ request }) => {
  const available = await request.get(`${API_BASE}/pet/findByStatus?status=available`, {
    headers: { Accept: 'application/json' },
  });
  expect(available.status()).toBe(200);
  const list = await available.json();
  expect(Array.isArray(list)).toBe(true);
  // Every returned pet actually carries the requested status.
  for (const pet of list) {
    expect(pet.status).toBe('available');
  }

  // An out-of-enum status value is rejected by the generated query rule.
  const bad = await request.get(`${API_BASE}/pet/findByStatus?status=teleporting`, {
    headers: { Accept: 'application/json' },
  });
  expect(bad.status()).toBe(422);
});

// ---------------------------------------------------------------------------
// Scenario 14: store/inventory map-valued response (additionalProperties int).
//
// getInventory returns an object with additionalProperties: integer. The
// response must be a JSON object of string -> integer, never an array.
// ---------------------------------------------------------------------------

test('GET /store/inventory returns a string-to-integer map object', async ({ request }) => {
  const res = await request.get(`${API_BASE}/store/inventory`, {
    headers: { Accept: 'application/json', 'X-API-Key': UPLOAD_API_KEY },
  });
  expect(res.status()).toBe(200);
  const text = await res.text();
  // A map response must be a JSON object, not an array.
  expect(text.trim().startsWith('{')).toBe(true);
  const body = await res.json();
  expect(Array.isArray(body)).toBe(false);
  // Every value is an integer (string keys -> integer counts).
  for (const value of Object.values(body)) {
    expect(Number.isInteger(value)).toBe(true);
  }
});

// ===========================================================================
// API-CONTRACT TIER: the stateless /lab/* endpoints.
//
// These hit the backend directly via Playwright's `request` fixture (no UI):
// each endpoint validates a crafted JSON body against the GENERATED rules() and
// echoes the hydrated object back. So one POST proves BOTH sides of the round
// trip at once: validation (a violation 422s) and serialization/hydration (a
// valid payload comes back correctly shaped).
//
// Assertions encode what the project PROMISES (CLAUDE.md "Current state"). Where
// a strict assertion diverges from real behavior, it is either (a) reconciled to
// a documented residual with a citing comment, or (b) left at the promised
// contract and marked test.fixme with a loud comment + a reported bug.
//
// NOTE ON STATUS: spatie/laravel-data serializes a Data object returned from a
// POST as 201 Created (not 200). Every lab echo is a POST returning Data, so the
// success status is 201. This was established in Pass 2 and is laravel-data's
// framework default, independent of any middleware.
// ===========================================================================

const LAB_OK = 201;

/** POST JSON to a /lab endpoint and return { status, body }. */
async function labPost(
  request: import('@playwright/test').APIRequestContext,
  endpoint: string,
  payload: unknown,
): Promise<{ status: number; body: any }> {
  const res = await request.post(`${API_BASE}/lab/${endpoint}`, {
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    data: payload,
  });
  let body: any = null;
  try {
    body = await res.json();
  } catch {
    body = null;
  }
  return { status: res.status(), body };
}

// ---------------------------------------------------------------------------
// A. VALIDATION rules (generated rules(), enforced by the real Laravel validator)
// ---------------------------------------------------------------------------

test('lab/numeric: bounds, exclusive bounds, and multipleOf round-trip and reject', async ({ request }) => {
  // Valid: 10 <= bounded <= 20, 0 < exclusive < 1, multiple of 5.
  const ok = await labPost(request, 'numeric', { bounded: 15, exclusive: 0.5, multiple: 25 });
  expect(ok.status).toBe(LAB_OK);
  expect(ok.body).toEqual({ bounded: 15, exclusive: 0.5, multiple: 25 });

  // minimum / maximum (min:10, max:20).
  expect((await labPost(request, 'numeric', { bounded: 9, exclusive: 0.5, multiple: 25 })).status).toBe(422);
  expect((await labPost(request, 'numeric', { bounded: 21, exclusive: 0.5, multiple: 25 })).status).toBe(422);
  // exclusiveMinimum / exclusiveMaximum (gt:0, lt:1) reject the boundary itself.
  expect((await labPost(request, 'numeric', { bounded: 15, exclusive: 0, multiple: 25 })).status).toBe(422);
  expect((await labPost(request, 'numeric', { bounded: 15, exclusive: 1, multiple: 25 })).status).toBe(422);
  // multipleOf (custom MultipleOfRule(5)).
  expect((await labPost(request, 'numeric', { bounded: 15, exclusive: 0.5, multiple: 23 })).status).toBe(422);
});

test('lab/string: minLength, maxLength, and pattern round-trip and reject', async ({ request }) => {
  const ok = await labPost(request, 'string', { sized: 'hello', coded: 'AB-1234' });
  expect(ok.status).toBe(LAB_OK);
  expect(ok.body).toEqual({ sized: 'hello', coded: 'AB-1234' });

  expect((await labPost(request, 'string', { sized: 'ab', coded: 'AB-1234' })).status).toBe(422); // min:3
  expect((await labPost(request, 'string', { sized: 'toolongvalue', coded: 'AB-1234' })).status).toBe(422); // max:8
  expect((await labPost(request, 'string', { sized: 'hello', coded: 'bad-code' })).status).toBe(422); // regex
});

// ---------------------------------------------------------------------------
// CONFIG FEATURE: controllers.base_class (#83)
// ---------------------------------------------------------------------------
// config/openapi-laravel.php sets controllers.base_class = BaseApiController, so
// the generator emits `abstract class Abstract*Controller extends
// BaseApiController`. BaseApiController declares the BaseClassMarker middleware
// via Laravel 12's HasMiddleware interface, which stamps an X-Base-Class header
// on every response handled by a generated controller. Asserting that header
// over real HTTP proves the generated abstract truly extends the configured base
// and the inheritance is live at runtime, not just textually in the file.
test('controllers.base_class (#83): every generated-controller route carries the X-Base-Class header from the configured base', async ({ request }) => {
  // A lab GET handled by the generated LabController (extends AbstractLabController
  // extends BaseApiController). labHeader echoes a constrained header param; we
  // only care that the base-class marker fires, so send a valid token.
  const res = await request.get(`${API_BASE}/lab/header`, {
    headers: { Accept: 'application/json', 'X-Lab-Token': 'tok-1234' },
  });
  expect(res.status()).toBe(200);
  expect(res.headers()['x-base-class']).toBe('openapi-laravel');

  // It is not specific to one controller: a generated PetController route carries
  // it too, since every generated abstract extends the same configured base.
  const pets = await request.get(`${API_BASE}/pet/findByStatus?status=available`, {
    headers: { Accept: 'application/json' },
  });
  expect(pets.status()).toBe(200);
  expect(pets.headers()['x-base-class']).toBe('openapi-laravel');
});

// ---------------------------------------------------------------------------
// CONFIG FEATURE: output.validation_trait (#83)
// ---------------------------------------------------------------------------
// config/openapi-laravel.php sets output.validation_trait =
// App\Validation\CustomValidationMessages, so the generator weaves a
// `use CustomValidationMessages;` line into every generated Data class. The trait
// returns CUSTOM messages for the /lab/trait-check `code` field (required + regex
// rules). laravel-data discovers the static messages() via method_exists() and
// feeds it to the Laravel validator, so a missing/malformed code 422s with the
// EXACT custom string. That equality is the proof the trait is woven in and
// honored, not just present in the file.
test('output.validation_trait (#83): the woven trait returns the exact custom 422 messages for /lab/trait-check code', async ({ request }) => {
  // Valid code round-trips (proves the rule is the trait-bearing class's rule()).
  const ok = await labPost(request, 'trait-check', { code: 'ABC-123' });
  expect(ok.status).toBe(LAB_OK);
  expect(ok.body).toEqual({ code: 'ABC-123' });

  // Missing code -> trait's code.required message, byte-for-byte.
  const missing = await labPost(request, 'trait-check', {});
  expect(missing.status).toBe(422);
  expect(missing.body.errors.code[0]).toBe('CUSTOM: code is required');

  // Malformed code -> trait's code.regex message, byte-for-byte.
  const malformed = await labPost(request, 'trait-check', { code: 'nope' });
  expect(malformed.status).toBe(422);
  expect(malformed.body.errors.code[0]).toBe('CUSTOM: code is malformed');
});

test('lab/array: minItems, maxItems, and uniqueItems round-trip and reject', async ({ request }) => {
  const ok = await labPost(request, 'array', { bag: ['a', 'b'], distinct: [1, 2, 3] });
  expect(ok.status).toBe(LAB_OK);
  expect(ok.body).toEqual({ bag: ['a', 'b'], distinct: [1, 2, 3] });

  expect((await labPost(request, 'array', { bag: ['a'], distinct: [1] })).status).toBe(422); // min:2
  expect((await labPost(request, 'array', { bag: ['a', 'b', 'c', 'd', 'e'], distinct: [1] })).status).toBe(422); // max:4
  expect((await labPost(request, 'array', { bag: ['a', 'b'], distinct: [1, 1, 2] })).status).toBe(422); // uniqueItems
});

test('lab/formats: date, date-time, time, duration, email, uuid, hostname round-trip and reject', async ({ request }) => {
  const valid = {
    day: '2026-01-15',
    moment: '2026-01-15T10:30:00Z',
    clock: '10:30:00',
    span: 'P1DT2H',
    mail: 'a@b.com',
    identifier: '550e8400-e29b-41d4-a716-446655440000',
    host: 'example.com',
  };
  const ok = await labPost(request, 'formats', valid);
  expect(ok.status).toBe(LAB_OK);
  expect(ok.body).toEqual(valid);

  expect((await labPost(request, 'formats', { ...valid, day: '15-01-2026' })).status).toBe(422); // date
  expect((await labPost(request, 'formats', { ...valid, moment: 'not-a-datetime' })).status).toBe(422); // date-time
  expect((await labPost(request, 'formats', { ...valid, clock: '99:99:99' })).status).toBe(422); // time
  expect((await labPost(request, 'formats', { ...valid, span: '2 hours' })).status).toBe(422); // duration
  expect((await labPost(request, 'formats', { ...valid, mail: 'notanemail' })).status).toBe(422); // email
  expect((await labPost(request, 'formats', { ...valid, identifier: 'not-a-uuid' })).status).toBe(422); // uuid
  expect((await labPost(request, 'formats', { ...valid, host: 'not a host!' })).status).toBe(422); // hostname
});

test('lab/enum-const: enum membership and const round-trip and reject', async ({ request }) => {
  const ok = await labPost(request, 'enum-const', { color: 'green', version: 'v2' });
  expect(ok.status).toBe(LAB_OK);
  expect(ok.body).toEqual({ color: 'green', version: 'v2' });

  expect((await labPost(request, 'enum-const', { color: 'purple', version: 'v2' })).status).toBe(422); // enum
  expect((await labPost(request, 'enum-const', { color: 'green', version: 'v1' })).status).toBe(422); // const (Rule::in(['v2']))
});

test('lab/closed: additionalProperties:false rejects an unknown key', async ({ request }) => {
  const ok = await labPost(request, 'closed', { known: 'yes' });
  expect(ok.status).toBe(LAB_OK);
  expect(ok.body).toEqual({ known: 'yes' });

  // The generated NoUnknownPropertiesRule rejects any key not in the schema.
  expect((await labPost(request, 'closed', { known: 'yes', surprise: 'boom' })).status).toBe(422);
});

test('lab/presence: required, nullable, optional, and default behave per the contract', async ({ request }) => {
  // mandatory is required; nullableField/optionalField default to null; withDefault
  // fills the spec default when omitted.
  const omitted = await labPost(request, 'presence', { mandatory: 'here' });
  expect(omitted.status).toBe(LAB_OK);
  expect(omitted.body).toEqual({
    mandatory: 'here',
    nullableField: null,
    optionalField: null,
    withDefault: 'fallback', // the spec default appears in the response
  });

  // missing required field 422s.
  expect((await labPost(request, 'presence', { nullableField: 'x' })).status).toBe(422);

  // explicit null on the nullable field is accepted and stays null.
  const explicitNull = await labPost(request, 'presence', { mandatory: 'here', nullableField: null });
  expect(explicitNull.status).toBe(LAB_OK);
  expect(explicitNull.body.nullableField).toBeNull();

  // overriding the default is honored.
  const overridden = await labPost(request, 'presence', { mandatory: 'here', withDefault: 'custom' });
  expect(overridden.body.withDefault).toBe('custom');
});

// ---------------------------------------------------------------------------
// B. SERIALIZATION / HYDRATION round-trips
// ---------------------------------------------------------------------------

test('lab/map: typed additionalProperties map round-trips, and an empty map serializes as {} not []', async ({ request }) => {
  const nonEmpty = await labPost(request, 'map', { label: 'x', counts: { a: 1, b: 2 } });
  expect(nonEmpty.status).toBe(LAB_OK);
  expect(nonEmpty.body.counts).toEqual({ a: 1, b: 2 });

  // The keystone: an EMPTY map must serialize as a JSON object {}, NOT an array
  // []. The generated MapObjectTransformer forces object encoding. We check the
  // raw text so [] vs {} is unambiguous.
  const emptyRes = await request.post(`${API_BASE}/lab/map`, {
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    data: { label: 'x', counts: {} },
  });
  expect(emptyRes.status()).toBe(LAB_OK);
  const emptyText = await emptyRes.text();
  expect(emptyText).toContain('"counts":{}');
  expect(emptyText).not.toContain('"counts":[]');

  // a non-integer map value 422s.
  expect((await labPost(request, 'map', { label: 'x', counts: { a: 'bad' } })).status).toBe(422);
});

test('lab/union: oneOf scalar union hydrates BOTH variants without coercion', async ({ request }) => {
  const asString = await labPost(request, 'union', { value: 'hello' });
  expect(asString.status).toBe(LAB_OK);
  expect(asString.body.value).toBe('hello');
  expect(typeof asString.body.value).toBe('string');

  const asInt = await labPost(request, 'union', { value: 42 });
  expect(asInt.status).toBe(LAB_OK);
  expect(asInt.body.value).toBe(42);
  expect(typeof asInt.body.value).toBe('number'); // stays an integer, not "42"
});

test('lab/nested: nested object + collection of objects round-trip', async ({ request }) => {
  const payload = {
    title: 'T',
    inner: { label: 'L', weight: 1.5 },
    items: [{ label: 'a' }, { label: 'b', weight: 2 }],
  };
  const ok = await labPost(request, 'nested', payload);
  expect(ok.status).toBe(LAB_OK);
  expect(ok.body.title).toBe('T');
  expect(ok.body.inner).toEqual({ label: 'L', weight: 1.5 });
  expect(ok.body.items).toHaveLength(2);
  expect(ok.body.items[0].label).toBe('a');
  expect(ok.body.items[1]).toEqual({ label: 'b', weight: 2 });

  // a violation deep in the nested object 422s.
  expect((await labPost(request, 'nested', { title: 'T', inner: { weight: 1 }, items: [] })).status).toBe(422);
});

test('lab/backed-enum: a named string enum component round-trips and rejects out-of-enum', async ({ request }) => {
  const ok = await labPost(request, 'backed-enum', { priority: 'high' });
  expect(ok.status).toBe(LAB_OK);
  expect(ok.body).toEqual({ priority: 'high' });

  expect((await labPost(request, 'backed-enum', { priority: 'urgent' })).status).toBe(422);
});

// ---------------------------------------------------------------------------
// C. COMPOSITION / ADVANCED
// ---------------------------------------------------------------------------

test('lab/allof: allOf merged-flat object round-trips both branches and rejects a missing field', async ({ request }) => {
  const ok = await labPost(request, 'allof', { baseField: 'b', extraField: 7 });
  expect(ok.status).toBe(LAB_OK);
  expect(ok.body).toEqual({ baseField: 'b', extraField: 7 });

  expect((await labPost(request, 'allof', { extraField: 7 })).status).toBe(422); // missing base branch field
  expect((await labPost(request, 'allof', { baseField: 'b' })).status).toBe(422); // missing extra branch field
});

test('lab/shape: discriminated object union hydrates each variant by its discriminator', async ({ request }) => {
  // circle variant
  const circle = await labPost(request, 'shape', { kind: 'circle', radius: 2.5 });
  expect(circle.status).toBe(LAB_OK);
  expect(circle.body.kind).toBe('circle');
  expect(circle.body.radius).toBe(2.5);
  expect(circle.body.side).toBeUndefined(); // hydrated to the circle shape, not square

  // square variant
  const square = await labPost(request, 'shape', { kind: 'square', side: 3 });
  expect(square.status).toBe(LAB_OK);
  expect(square.body.kind).toBe('square');
  expect(square.body.side).toBe(3);
  expect(square.body.radius).toBeUndefined();

  // a variant-specific rule still fires after the morph (circle.radius gt:0).
  expect((await labPost(request, 'shape', { kind: 'circle', radius: 0 })).status).toBe(422);
  // a missing variant field 422s.
  expect((await labPost(request, 'shape', { kind: 'circle' })).status).toBe(422);
});

// An UNKNOWN discriminator value on a named-component discriminated union is
// rejected with 422 (fixed in #124 / PR #127: the generated morph() now throws a
// ValidationException on an unmapped `kind` instead of returning null, so the
// spec-invalid value is a clean 422 on every consumption path, not a 500).
test('lab/shape: an unknown discriminator value is rejected with a 422', async ({ request }) => {
  const unknown = await labPost(request, 'shape', { kind: 'triangle', radius: 1 });
  expect(unknown.status).toBe(422);
});

test('lab/ref-body: a component $ref request body (#110) and $ref response (#116) round-trip with a typed Data class', async ({ request }) => {
  // The spec wires this operation's body via requestBodies/LabRefBody (a $ref to
  // LabRefPayload) and its response via responses/LabRefResponse (the same $ref).
  // The generator resolved BOTH to the typed LabRefPayloadData param + return.
  const ok = await labPost(request, 'ref-body', { note: 'hi', amount: 5 });
  expect(ok.status).toBe(LAB_OK);
  expect(ok.body).toEqual({ note: 'hi', amount: 5 });

  // the resolved component's rules() are enforced (amount min:1, note min:1).
  expect((await labPost(request, 'ref-body', { note: 'hi', amount: 0 })).status).toBe(422);
  expect((await labPost(request, 'ref-body', { note: '', amount: 5 })).status).toBe(422);
});

// A spec-declared 202 success status on a POST returning a Data object is
// honored (fixed in #125 / PR #128: RespondsWithStatus now normalizes any
// framework-default 2xx to the declared status, so laravel-data's 201-on-POST
// default no longer pre-empts the declared 202).
test('lab/accepted: a 202 success status is honored by RespondsWithStatus', async ({ request }) => {
  const res = await request.post(`${API_BASE}/lab/accepted`, {
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    data: { baseField: 'b', extraField: 7 },
  });
  expect(res.status()).toBe(202);
});

// ---------------------------------------------------------------------------
// D. UPLOAD: the recorded image URL actually serves the bytes
// ---------------------------------------------------------------------------

test('uploaded image is fetchable at its /storage URL and matches the uploaded pixel', async ({ request }) => {
  const fs = require('node:fs') as typeof import('node:fs');
  const png = fs.readFileSync(PNG_FIXTURE);

  // Upload to seeded pet 1 (Rex) with the API key.
  const upload = await request.post(`${API_BASE}/pet/1/uploadImage`, {
    headers: { Accept: 'application/json', 'X-API-Key': UPLOAD_API_KEY },
    multipart: { image: { name: 'pixel.png', mimeType: 'image/png', buffer: png } },
  });
  expect(upload.status()).toBe(201); // laravel-data POST returns 201
  const message: string = (await upload.json()).message;

  // Pull the recorded /storage/uploads/<file>.png URL out of the echoed message.
  const match = message.match(/\/storage\/uploads\/[^\s)]+\.png/);
  expect(match).not.toBeNull();
  const storedUrl = match![0];

  // The public storage symlink (php artisan storage:link, run at container
  // start) makes that URL serve the actual bytes.
  const img = await request.get(`${SERVER_ORIGIN}${storedUrl}`);
  expect(img.status()).toBe(200);
  expect(img.headers()['content-type']).toContain('image/png');
  const served = await img.body();
  expect(served.length).toBe(png.length); // non-empty, same size as the uploaded pixel
  expect(Buffer.compare(served, png)).toBe(0); // byte-identical to what we uploaded
});

// ===========================================================================
// PASS 4: parameter axis, more composition forms, server surfaces.
// All API-contract tier (raw HTTP via the request fixture).
// ===========================================================================

/** GET a /lab endpoint and return { status, body, headers }. */
async function labGet(
  request: import('@playwright/test').APIRequestContext,
  pathAndQuery: string,
  extraHeaders: Record<string, string> = {},
): Promise<{ status: number; body: any; headers: Record<string, string> }> {
  const res = await request.get(`${API_BASE}/lab/${pathAndQuery}`, {
    headers: { Accept: 'application/json', ...extraHeaders },
  });
  let body: any = null;
  try {
    body = await res.json();
  } catch {
    body = null;
  }
  return { status: res.status(), body, headers: res.headers() };
}

// ---------------------------------------------------------------------------
// Stage 0 / 1A: QUERY PARAMS. Stage 0 probe RESULT: query validation FIRES at
// runtime (a bad enum/range/pattern 422s). These tests lock that in.
// ---------------------------------------------------------------------------

test('lab/query: constrained query params (enum + min/max + pattern) round-trip and reject', async ({ request }) => {
  const ok = await labGet(request, 'query?tier=gold&count=50&code=ABC');
  expect(ok.status).toBe(200);
  expect(ok.body).toEqual({ tier: 'gold', count: 50, code: 'ABC' });

  expect((await labGet(request, 'query?tier=platinum&count=50&code=ABC')).status).toBe(422); // enum
  expect((await labGet(request, 'query?tier=gold&count=0&code=ABC')).status).toBe(422); // min:1
  expect((await labGet(request, 'query?tier=gold&count=999&code=ABC')).status).toBe(422); // max:100
  expect((await labGet(request, 'query?tier=gold&count=50&code=abcd')).status).toBe(422); // pattern ^[A-Z]{3}$
  // a missing required query param 422s.
  expect((await labGet(request, 'query?count=50&code=ABC')).status).toBe(422);
});

// ---------------------------------------------------------------------------
// Stage 1B: PATH PARAM constraint (#113, now shipped). The generator emits
// LabPathPathData with a fromRoute() factory; the concrete controller calls it,
// so the path-segment min/max is enforced at runtime: an in-range value
// round-trips, an out-of-range value is a 422 with `score` in the error bag.
// ---------------------------------------------------------------------------

test('lab/path: a valid in-range path param round-trips', async ({ request }) => {
  const ok = await labGet(request, 'path/15'); // 10 <= 15 <= 20
  expect(ok.status).toBe(200);
  expect(ok.body).toEqual({ score: 15 });
});

test('lab/path: an out-of-range path param is rejected with 422 (#113)', async ({ request }) => {
  const tooHigh = await labGet(request, 'path/99'); // > max 20
  expect(tooHigh.status).toBe(422);
  expect(tooHigh.body.errors).toHaveProperty('score');

  const tooLow = await labGet(request, 'path/5'); // < min 10
  expect(tooLow.status).toBe(422);
  expect(tooLow.body.errors).toHaveProperty('score');
});

// ---------------------------------------------------------------------------
// Stage 1C: HEADER PARAM constraint (#121, now shipped). The generator emits
// LabHeaderHeaderData with a fromHeaders() factory; the concrete controller
// calls it, so the X-Lab-Token pattern ^tok-[0-9]{4}$ is enforced at runtime: a
// valid token echoes, a bad value or a missing required header is a 422 with the
// header name (lowercased to `x-lab-token`) in the error bag.
// ---------------------------------------------------------------------------

test('lab/header: a valid constrained header round-trips (#121)', async ({ request }) => {
  const ok = await labGet(request, 'header', { 'X-Lab-Token': 'tok-1234' });
  expect(ok.status).toBe(200);
  expect(ok.body).toEqual({ token: 'tok-1234' });
});

test('lab/header: a bad or missing constrained header is rejected with 422 (#121)', async ({ request }) => {
  const bad = await labGet(request, 'header', { 'X-Lab-Token': 'garbage' }); // violates pattern
  expect(bad.status).toBe(422);
  expect(bad.body.errors).toHaveProperty('x-lab-token');

  const missing = await labGet(request, 'header'); // required header absent
  expect(missing.status).toBe(422);
  expect(missing.body.errors).toHaveProperty('x-lab-token');
});

// ---------------------------------------------------------------------------
// Stage 1D: routes.prefix + routes.middleware (#71). The generated routes are
// wrapped in one Route::group with prefix v1 and the route-group-marker
// middleware. Proven over real HTTP: only /api/v1 is reachable, and the group
// middleware stamps X-Route-Group on the response.
// ---------------------------------------------------------------------------

test('routes group (#71): prefix /v1 is applied and the group middleware runs', async ({ request }) => {
  // The prefix is applied: the un-prefixed path is NOT routed.
  const unprefixed = await request.get('http://localhost:8088/api/pet/findByStatus?status=available', {
    headers: { Accept: 'application/json' },
  });
  expect(unprefixed.status()).toBe(404);

  // The prefixed path is routed AND carries the group middleware's marker header.
  const prefixed = await request.get(`${API_BASE}/pet/findByStatus?status=available`, {
    headers: { Accept: 'application/json' },
  });
  expect(prefixed.status()).toBe(200);
  expect(prefixed.headers()['x-route-group']).toBe('v1');
});

// ---------------------------------------------------------------------------
// Stage 1E: #77 SECURITY MIDDLEWARE MATRIX (AND / OR / public), end-to-end.
//
// Four GET lab ops carry operation-level `security`. config security.
// middleware_map maps lab_session -> lab-session and lab_team -> lab-team, so
// the generator stamps the route middleware per the documented rules:
//   - secure-single  security: [{ lab_session }]            -> [lab-session]
//   - secure-and     security: [{ lab_session, lab_team }]  -> [lab-session, lab-team]   (AND: both apply)
//   - secure-or      security: [{ lab_session }, { lab_team }] -> [lab-session]           (OR: ONLY the first
//                     requirement object is enforced; the generator drops lab_team and warns)
//   - secure-public  security: []                            -> (no auth middleware)
//
// These are GET ops returning a LabSecureEchoData, so a success is 200 (the 201
// promotion is POST-only, see CreatedResponse). A 200 here means the FULL
// stamped middleware stack admitted the request; a 401 means a required guard
// rejected it. The two guard tokens are sess-1234 (X-Lab-Session) and team-1234
// (X-Lab-Team), fixed in the LabSession / LabTeam middleware.
// ---------------------------------------------------------------------------

const LAB_SESSION_TOKEN = 'sess-1234';
const LAB_TEAM_TOKEN = 'team-1234';

test('lab/secure-single (#77): one scheme is enforced, missing creds 401 and valid creds 200', async ({ request }) => {
  // No X-Lab-Session header: the lab-session guard rejects.
  const missing = await labGet(request, 'secure-single');
  expect(missing.status).toBe(401);

  // Valid token: the single required scheme passes, the echo comes back.
  const ok = await labGet(request, 'secure-single', { 'X-Lab-Session': LAB_SESSION_TOKEN });
  expect(ok.status).toBe(200);
  expect(ok.body).toEqual({ ok: true, op: 'secure-single' });
});

test('lab/secure-and (#77): BOTH schemes in one requirement object apply (AND)', async ({ request }) => {
  // Both headers valid: the whole AND stack passes.
  const both = await labGet(request, 'secure-and', {
    'X-Lab-Session': LAB_SESSION_TOKEN,
    'X-Lab-Team': LAB_TEAM_TOKEN,
  });
  expect(both.status).toBe(200);
  expect(both.body).toEqual({ ok: true, op: 'secure-and' });

  // Drop the team header: lab-team rejects, proving lab-team is in the stack.
  const noTeam = await labGet(request, 'secure-and', { 'X-Lab-Session': LAB_SESSION_TOKEN });
  expect(noTeam.status).toBe(401);

  // Drop the session header: lab-session rejects, proving it is in the stack too.
  const noSession = await labGet(request, 'secure-and', { 'X-Lab-Team': LAB_TEAM_TOKEN });
  expect(noSession.status).toBe(401);
});

test('lab/secure-or (#77): ONLY the first requirement object is enforced (documented OR caveat)', async ({ request }) => {
  // The spec OR is [{ lab_session }, { lab_team }]. Middleware cannot express
  // OR, so the generator enforces ONLY the FIRST requirement object
  // (lab_session) and warns, dropping lab_team. This is the documented,
  // possibly-surprising behavior and we assert it EXPLICITLY:

  // First requirement satisfied (X-Lab-Session valid): 200.
  const firstOk = await labGet(request, 'secure-or', { 'X-Lab-Session': LAB_SESSION_TOKEN });
  expect(firstOk.status).toBe(200);
  expect(firstOk.body).toEqual({ ok: true, op: 'secure-or' });

  // First requirement MISSING but the SECOND (X-Lab-Team) satisfied: still 401,
  // because lab_team was never stamped onto the route. A real OR would accept
  // this; the generator's first-only behavior rejects it. This is the caveat.
  const secondOnly = await labGet(request, 'secure-or', { 'X-Lab-Team': LAB_TEAM_TOKEN });
  expect(secondOnly.status).toBe(401);
});

test('lab/secure-public (#77): security:[] carries no auth middleware and is reachable with no creds', async ({ request }) => {
  const ok = await labGet(request, 'secure-public');
  expect(ok.status).toBe(200);
  expect(ok.body).toEqual({ ok: true, op: 'secure-public' });
});

// ---------------------------------------------------------------------------
// Stage 2: COMPOSITION FORMS
// ---------------------------------------------------------------------------

test('lab/inline-body (#76): an INLINE object request body is synthesized, validates, and round-trips', async ({ request }) => {
  // labInlineBody returns a JsonResponse (not a Data object), so the success
  // status is 200, not the laravel-data 201.
  const ok = await labPost(request, 'inline-body', { title: 'hello', rank: 3 });
  expect(ok.status).toBe(200);
  expect(ok.body).toEqual({ title: 'hello', rank: 3 });

  expect((await labPost(request, 'inline-body', { title: 'x', rank: 3 })).status).toBe(422); // minLength:2
  expect((await labPost(request, 'inline-body', { title: 'hello', rank: 9 })).status).toBe(422); // max:5
  expect((await labPost(request, 'inline-body', { title: 'hello' })).status).toBe(422); // required rank
});

test('lab/shared-one + shared-two (#110/#116): two ops share ONE inline-object component class', async ({ request }) => {
  const one = await labPost(request, 'shared-one', { sku: 'SKU-123', qty: 2 });
  expect(one.status).toBe(LAB_OK);
  expect(one.body).toEqual({ sku: 'SKU-123', qty: 2 });

  const two = await labPost(request, 'shared-two', { sku: 'SKU-999', qty: 5 });
  expect(two.status).toBe(LAB_OK);
  expect(two.body).toEqual({ sku: 'SKU-999', qty: 5 });

  // The shared component's rules() fire identically on both ops.
  expect((await labPost(request, 'shared-one', { sku: 'BAD', qty: 2 })).status).toBe(422); // sku pattern
  expect((await labPost(request, 'shared-two', { sku: 'SKU-123', qty: 0 })).status).toBe(422); // qty min:1
});

test('lab/inline-shape (#38 inline-union): variants hydrate by discriminator with synthesized names', async ({ request }) => {
  const dog = await labPost(request, 'inline-shape', { petType: 'dog', bark: 'woof' });
  expect(dog.status).toBe(LAB_OK);
  expect(dog.body.petType).toBe('dog');
  expect(dog.body.bark).toBe('woof');
  expect(dog.body.meow).toBeUndefined(); // hydrated to the dog variant, not cat

  const cat = await labPost(request, 'inline-shape', { petType: 'cat', meow: 'mrr' });
  expect(cat.status).toBe(LAB_OK);
  expect(cat.body.petType).toBe('cat');
  expect(cat.body.meow).toBe('mrr');
  expect(cat.body.bark).toBeUndefined();

  // an UNKNOWN discriminator value is a clean 422 (the #124 fix applies here too).
  expect((await labPost(request, 'inline-shape', { petType: 'fish', bark: 'x' })).status).toBe(422);
});

test('lab/inherit-shape (#38 allOf-inheritance): variants hydrate and the variant rule fires', async ({ request }) => {
  const car = await labPost(request, 'inherit-shape', { vehicleType: 'car', wheels: 4 });
  expect(car.status).toBe(LAB_OK);
  expect(car.body.vehicleType).toBe('car');
  expect(car.body.wheels).toBe(4);
  expect(car.body.draft).toBeUndefined();

  const boat = await labPost(request, 'inherit-shape', { vehicleType: 'boat', draft: 1.5 });
  expect(boat.status).toBe(LAB_OK);
  expect(boat.body.vehicleType).toBe('boat');
  expect(boat.body.draft).toBe(1.5);
  expect(boat.body.wheels).toBeUndefined();

  // the variant-specific rule fires after the morph (car.wheels min:3).
  expect((await labPost(request, 'inherit-shape', { vehicleType: 'car', wheels: 2 })).status).toBe(422);
  // an UNKNOWN discriminator value is a clean 422 (#124).
  expect((await labPost(request, 'inherit-shape', { vehicleType: 'plane', wheels: 4 })).status).toBe(422);
});

// A MISSING discriminator (the property absent entirely) is now a clean 422,
// not a 500. On the controller-injection consumption path the framework routes
// through the morphable base's morph(), whose default arm (#124) throws a
// ValidationException keyed on the discriminator field when the value is null or
// unmapped, so an absent discriminator rejects before any 500 fires. Covers BOTH
// discriminated forms reachable here (inline-union and allOf-inheritance).
test('lab/shape: a MISSING discriminator is rejected with 422, not 500 (#124)', async ({ request }) => {
  const inline = await labPost(request, 'inline-shape', { bark: 'woof' }); // no petType
  expect(inline.status).toBe(422);
  expect(inline.body.errors).toHaveProperty('petType');

  const inherit = await labPost(request, 'inherit-shape', { wheels: 4 }); // no vehicleType
  expect(inherit.status).toBe(422);
  expect(inherit.body.errors).toHaveProperty('vehicleType');
});

test('lab/response-union (#116): a oneOf-of-Data-class response returns each shape correctly', async ({ request }) => {
  const circle = await labPost(request, 'response-union', { want: 'circle' });
  expect(circle.status).toBe(LAB_OK);
  expect(circle.body.kind).toBe('circle');
  expect(circle.body.radius).toBe(1.5);
  expect(circle.body.side).toBeUndefined();

  const square = await labPost(request, 'response-union', { want: 'square' });
  expect(square.status).toBe(LAB_OK);
  expect(square.body.kind).toBe('square');
  expect(square.body.side).toBe(4);
  expect(square.body.radius).toBeUndefined();

  // the selector enum is validated.
  expect((await labPost(request, 'response-union', { want: 'hexagon' })).status).toBe(422);
});

test('lab/anyof-union: an anyOf scalar union hydrates BOTH variants without coercion', async ({ request }) => {
  const asBool = await labPost(request, 'anyof-union', { value: true });
  expect(asBool.status).toBe(LAB_OK);
  expect(asBool.body.value).toBe(true);
  expect(typeof asBool.body.value).toBe('boolean');

  const asInt = await labPost(request, 'anyof-union', { value: 7 });
  expect(asInt.status).toBe(LAB_OK);
  expect(asInt.body.value).toBe(7);
  expect(typeof asInt.body.value).toBe('number');
});

// ---------------------------------------------------------------------------
// Stage 3: SERVER / RESPONSE SURFACES
// ---------------------------------------------------------------------------

test('lab/plain-text (#117/#118): a text/plain response serves the right content-type and body', async ({ request }) => {
  // The generator types a non-JSON response as the base Response and warns; the
  // body is consumer-written. We assert the runtime serves it correctly.
  const res = await request.get(`${API_BASE}/lab/plain-text`);
  expect(res.status()).toBe(200);
  expect(res.headers()['content-type']).toContain('text/plain');
  expect(await res.text()).toBe('lab plain text body');
});

test('lab/download (#117/#118): a binary response serves octet-stream bytes', async ({ request }) => {
  const res = await request.get(`${API_BASE}/lab/download`);
  expect(res.status()).toBe(200);
  expect(res.headers()['content-type']).toContain('application/octet-stream');
  const body = await res.body();
  expect(body.length).toBeGreaterThan(0);
  expect(body.toString('latin1')).toContain('LABDOWNLOAD');
});

test('lab/gallery (#75 edge): a multipart array of binary files validates per-file and round-trips the count', async ({ request }) => {
  const fs = require('node:fs') as typeof import('node:fs');
  const png = fs.readFileSync(PNG_FIXTURE);
  const txt = fs.readFileSync(TXT_FIXTURE);

  // Two valid PNG parts + an album field.
  const ok = await request.post(`${API_BASE}/lab/gallery`, {
    headers: { Accept: 'application/json' },
    multipart: {
      'photos[0]': { name: 'a.png', mimeType: 'image/png', buffer: png },
      'photos[1]': { name: 'b.png', mimeType: 'image/png', buffer: png },
      album: 'summer',
    },
  });
  expect(ok.status()).toBe(201);
  expect((await ok.json()).message).toContain('Received 2 photo(s) in album summer');

  // A non-PNG part is rejected by the generated photos.* file/mimetypes rule.
  const bad = await request.post(`${API_BASE}/lab/gallery`, {
    headers: { Accept: 'application/json' },
    multipart: {
      'photos[0]': { name: 'a.txt', mimeType: 'text/plain', buffer: txt },
    },
  });
  expect(bad.status()).toBe(422);
});

test('lab/tuple (#82): prefixItems validate per position (and the value round-trips)', async ({ request }) => {
  const ok = await labPost(request, 'tuple', { pair: ['hi', 5] });
  expect(ok.status).toBe(LAB_OK);
  expect(ok.body.pair).toEqual(['hi', 5]);

  // position 1 is typed integer (a string there 422s).
  expect((await labPost(request, 'tuple', { pair: ['hi', 'notint'] })).status).toBe(422);
  // position 1 carries the per-position min:0 rule.
  expect((await labPost(request, 'tuple', { pair: ['hi', -3] })).status).toBe(422);
});

// ---------------------------------------------------------------------------
// Stage 4: USER CRUD (#94). The user tag exposes a clean RESTful surface that
// the generator maps to Laravel-convention method names (store/show/update/
// destroy). The concrete UserController backs them with the in-memory store.
// This proves the full create -> read -> update -> delete loop plus the 404 arm
// over raw HTTP, end to end.
// ---------------------------------------------------------------------------

test('user CRUD (#94): POST 201, GET 200, PUT 204, DELETE 204, missing GET 404', async ({ request }) => {
  const username = `e2e-user-${Date.now()}`;
  const json = { Accept: 'application/json', 'Content-Type': 'application/json' };

  // POST /user creates and returns the user as a Data object -> 201.
  const created = await request.post(`${API_BASE}/user`, {
    headers: json,
    data: { username, firstName: 'Ada', lastName: 'Lovelace', email: 'ada@example.com', userStatus: 1 },
  });
  expect(created.status()).toBe(201);
  expect((await created.json()).username).toBe(username);

  // GET /user/{username} returns the exact stored body -> 200.
  const fetched = await request.get(`${API_BASE}/user/${username}`, { headers: { Accept: 'application/json' } });
  expect(fetched.status()).toBe(200);
  const body = await fetched.json();
  expect(body.username).toBe(username);
  expect(body.firstName).toBe('Ada');
  expect(body.lastName).toBe('Lovelace');
  expect(body.email).toBe('ada@example.com');
  expect(body.userStatus).toBe(1);

  // PUT /user/{username} updates and returns an empty body -> 204.
  const updated = await request.put(`${API_BASE}/user/${username}`, {
    headers: json,
    data: { username, firstName: 'Augusta', lastName: 'Lovelace', email: 'augusta@example.com', userStatus: 2 },
  });
  expect(updated.status()).toBe(204);

  // The update took effect.
  const refetched = await request.get(`${API_BASE}/user/${username}`, { headers: { Accept: 'application/json' } });
  expect(refetched.status()).toBe(200);
  expect((await refetched.json()).firstName).toBe('Augusta');

  // DELETE /user/{username} removes it -> 204.
  const deleted = await request.delete(`${API_BASE}/user/${username}`, { headers: { Accept: 'application/json' } });
  expect(deleted.status()).toBe(204);

  // A GET for the now-missing (or never-created) user -> 404.
  const missing = await request.get(`${API_BASE}/user/${username}`, { headers: { Accept: 'application/json' } });
  expect(missing.status()).toBe(404);
});

// ---------------------------------------------------------------------------
// Stage 4: ARRAY QUERY PARAM (#63). findByTags declares `tags` as a required
// array (explode:true). The PASSING contract uses the PHP bracket form
// `?tags[]=a&tags[]=b`, which PHP parses into a real array, so both tag values
// reach the validated FindPetsByTagsQueryData and both matching pets come back.
// A missing required `tags` is a 422.
//
// PHP RESIDUAL: the OpenAPI explode:true ideal is the repeated-key form
// `?tags=a&tags=b`. PHP collapses repeated query keys to the LAST value, so only
// the last tag survives. That is a PHP/runtime limitation, not a generator bug;
// the OpenAPI-ideal contract is parked as a clearly-labeled .fixme below.
// ---------------------------------------------------------------------------

test('findByTags (#63): the PHP bracket array form reflects BOTH tag values', async ({ request }) => {
  const json = { Accept: 'application/json', 'Content-Type': 'application/json' };
  const tagA = `e2e-tag-a-${Date.now()}`;
  const tagB = `e2e-tag-b-${Date.now()}`;

  // Seed two pets, one per distinct tag, so a both-tags query must return both.
  const petA = await request.post(`${API_BASE}/pet`, {
    headers: json,
    data: { name: 'TagPetA', photoUrls: ['https://example.com/a.png'], status: 'available', tags: [{ name: tagA }] },
  });
  expect(petA.status()).toBe(201);
  const idA = (await petA.json()).id as number;

  const petB = await request.post(`${API_BASE}/pet`, {
    headers: json,
    data: { name: 'TagPetB', photoUrls: ['https://example.com/b.png'], status: 'available', tags: [{ name: tagB }] },
  });
  expect(petB.status()).toBe(201);
  const idB = (await petB.json()).id as number;

  // The bracket array form: both tag values reach the validated query Data.
  const both = await request.get(
    `${API_BASE}/pet/findByTags?tags[]=${encodeURIComponent(tagA)}&tags[]=${encodeURIComponent(tagB)}`,
    { headers: { Accept: 'application/json' } },
  );
  expect(both.status()).toBe(200);
  const pets = await both.json();
  expect(Array.isArray(pets)).toBe(true);
  const names: string[] = pets.flatMap((p: any) => (p.tags ?? []).map((t: any) => t.name));
  expect(names).toContain(tagA);
  expect(names).toContain(tagB);

  // A missing required `tags` query param is a 422.
  const missing = await request.get(`${API_BASE}/pet/findByTags`, { headers: { Accept: 'application/json' } });
  expect(missing.status()).toBe(422);
  expect((await missing.json()).errors).toHaveProperty('tags');

  // clean up the throwaway pets.
  expect((await request.delete(`${API_BASE}/pet/${idA}`, { headers: { Accept: 'application/json' } })).status()).toBe(204);
  expect((await request.delete(`${API_BASE}/pet/${idB}`, { headers: { Accept: 'application/json' } })).status()).toBe(204);
});

// PHP RESIDUAL (parked, not a generator bug): the OpenAPI explode:true ideal is
// the repeated-key form `?tags=a&tags=b`. PHP collapses repeated query keys to
// the LAST value before the app sees them, so only `b` survives and the
// both-tags contract cannot hold. Kept as .fixme holding the OpenAPI-ideal so
// the limitation is documented without lying about runtime behavior.
test.fixme('findByTags (#63): the OpenAPI explode repeated-key form reflects BOTH values (PHP residual)', async ({ request }) => {
  const res = await request.get(`${API_BASE}/pet/findByTags?tags=alpha&tags=beta`, {
    headers: { Accept: 'application/json' },
  });
  expect(res.status()).toBe(200);
  // PHP keeps only the last repeated key, so `alpha` is lost; this is the ideal
  // contract that PHP cannot satisfy.
  const text = await res.text();
  expect(text).toContain('alpha');
  expect(text).toContain('beta');
});

// ===========================================================================
// E. RESIDUAL PINS over real HTTP
// ---------------------------------------------------------------------------
// These tests encode DOCUMENTED honest fallback behavior (CLAUDE.md "Honest
// residuals", ROADMAP.md) so a regression that silently makes the generator
// behave WORSE than documented is caught. Each test is reconciled to the
// documented residual with a citing comment. None weaken the contract: if a
// residual ever behaves worse than documented (a crash, a 500, silent
// acceptance where rejection is promised), the test would fail loudly rather
// than be quietly relaxed.
//
// The generate.sh warnings captured at generation time (verified verbatim):
//   - GET /lab/styles: query parameter "filter" was skipped: style "deepObject"
//     is not supported yet.
//   - GET /lab/styles: query parameter "ids" was skipped: style "pipeDelimited"
//     serializes the array into a single delimited value, which the generated
//     array rules cannot validate.
//   - GET /lab/cookie: cookie parameter(s) "session_hint" are not generated
//     (cookie parameters are not supported yet).
//   - Schema "LabLooseUnion": a oneOf/anyOf member is not a plain scalar or a
//     $ref to a generated Data class; the union degrades to mixed with
//     presence-only validation.
// ===========================================================================

// 1. NON-STANDARD QUERY STYLES are skipped + warned (RequestDataSynthesizer
// querySkipReason). The deepObject `filter` object and the pipeDelimited `ids`
// array were dropped at generation time and never reach the generated query
// Data class (which carries ONLY `page`). Proof over HTTP: arbitrary/garbage
// values for those params do NOT gate the request, because no validation exists
// for them. The supported `page` param is still validated.
test('lab/styles (residual): deepObject + pipeDelimited query params are skipped, not validated', async ({ request }) => {
  // Garbage values for the skipped styles do not matter: the op still 200s,
  // because filter/ids are absent from the generated query class entirely.
  const ok = await labGet(request, 'styles?filter[category]=!!!&filter[minPrice]=notanumber&ids=99|abc|7&page=3');
  expect(ok.status).toBe(200);
  expect(ok.body).toEqual({ page: 3 });

  // With no params at all the optional page falls back to the controller's 0.
  const bare = await labGet(request, 'styles');
  expect(bare.status).toBe(200);
  expect(bare.body).toEqual({ page: 0 });

  // The ONE param that WAS generated is still validated (page min:1 max:50):
  // proves the op is not just blanket-accepting everything.
  expect((await labGet(request, 'styles?page=0')).status).toBe(422);
  expect((await labGet(request, 'styles?page=999')).status).toBe(422);
});

// 2. COOKIE PARAMETER is dropped + warned (the scaffold keeps `cookie` in the
// unsupported-locations set). The required in:cookie `session_hint` is never
// typed or validated. Proof over HTTP: the op 200s regardless of the cookie
// value, and even with NO cookie at all, despite the spec marking it required.
test('lab/cookie (residual): an in:cookie param is dropped, never validated', async ({ request }) => {
  // No cookie supplied at all: the spec says required, but the generator dropped
  // it, so there is no validation and the op still returns 200.
  const noCookie = await labGet(request, 'cookie');
  expect(noCookie.status).toBe(200);
  expect(noCookie.body).toEqual({ ok: true });

  // A garbage cookie value (violates the spec pattern ^sess-[0-9]{4}$) is equally
  // ignored: still 200, never a 422.
  const garbage = await labGet(request, 'cookie', { Cookie: 'session_hint=totally-bogus' });
  expect(garbage.status).toBe(200);
  expect(garbage.body).toEqual({ ok: true });
});

// 3. int64 BOUNDS degrade gracefully (no crash). `ledger` is integer/int64 with
// min:1 max:9_000_000_000_000_000_000. On this 64-bit platform BOTH bounds fit
// PHP_INT_MAX (9223372036854775807), so the generator emitted REAL min:/max:
// rules rather than a docblock-only degradation. This test documents the ACTUAL
// current behavior: a normal in-range value round-trips, and the large bounds
// ARE enforced here (a value above the max 422s, below the min 422s).
test('lab/int64 (residual): int64 bounds do not crash; in-range round-trips and the emitted bounds are enforced', async ({ request }) => {
  // Normal in-range value round-trips. (JSON numbers up to 2^53 are safe in JS;
  // we stay well under that for the value itself.)
  const ok = await labPost(request, 'int64', { ledger: 123456 });
  expect(ok.status).toBe(LAB_OK);
  expect(ok.body).toEqual({ ledger: 123456 });

  // min:1 is enforced (0 is below it).
  expect((await labPost(request, 'int64', { ledger: 0 })).status).toBe(422);

  // The max:9_000_000_000_000_000_000 rule was emitted and is enforced here:
  // a value above it 422s. Sent as a raw JSON integer literal so PHP sees the
  // full magnitude (well within PHP's 64-bit int range). Documents that on a
  // 64-bit platform these int64 bounds are NOT degraded to docblock-only.
  const tooBig = await request.post(`${API_BASE}/lab/int64`, {
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    data: '{"ledger": 9100000000000000000}',
  });
  expect(tooBig.status()).toBe(422);
});

// 4. UNDISCRIMINATED OBJECT UNION -> mixed, presence-only (#31). `payload` is a
// oneOf of two object schemas with NO discriminator, so the generator typed it
// `mixed` with a presence-only `required` rule. Proof over HTTP: a body matching
// EITHER variant is accepted, AND there is NO variant-specific validation: a
// field that belongs to the "wrong" variant (or to neither) is NOT rejected.
// This is the documented #31 behavior, not a bug.
test('lab/loose-union (residual #31): undiscriminated object union is mixed + presence-only', async ({ request }) => {
  // Variant A shape ({alpha}) is accepted and round-trips untouched.
  const a = await labPost(request, 'loose-union', { payload: { alpha: 'hello' } });
  expect(a.status).toBe(LAB_OK);
  expect(a.body).toEqual({ payload: { alpha: 'hello' } });

  // Variant B shape ({beta}) is also accepted and round-trips.
  const b = await labPost(request, 'loose-union', { payload: { beta: 42 } });
  expect(b.status).toBe(LAB_OK);
  expect(b.body).toEqual({ payload: { beta: 42 } });

  // No variant-specific hydration/validation: a payload that matches NEITHER
  // variant's required field is STILL accepted (presence-only on `payload`).
  // A discriminated union would 422 this; #31 by design does not.
  const neither = await labPost(request, 'loose-union', { payload: { gamma: true } });
  expect(neither.status).toBe(LAB_OK);
  expect(neither.body).toEqual({ payload: { gamma: true } });

  // The ONLY rule is presence: a missing payload IS rejected (proves the
  // required rule fires and the op is not blanket-accepting an empty body).
  expect((await labPost(request, 'loose-union', {})).status).toBe(422);
});

// 5. ALTERNATIVE-2xx PASS-THROUGH (#64). /lab/dual-status declares BOTH 200 and
// 202. The generator selects the smallest 2xx (200) as the success status and
// emits NO RespondsWithStatus middleware on the route (only an exactly declared
// NON-200 selected success is rewritten). The 200 declares no body, so the
// method is typed JsonResponse; the concrete controller sets 202 explicitly.
// Proof over HTTP: the controller-set 202 stays 202, NOT clobbered to 200.
test('lab/dual-status (residual #64): a controller-set 202 passes through untouched (200 selected, no rewrite)', async ({ request }) => {
  const res = await request.get(`${API_BASE}/lab/dual-status`, {
    headers: { Accept: 'application/json' },
  });
  expect(res.status()).toBe(202);
  expect(await res.json()).toEqual({ state: 'accepted' });
});
