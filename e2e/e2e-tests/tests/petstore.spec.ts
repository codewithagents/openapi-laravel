import { test, expect, type Page } from '@playwright/test';
import path from 'node:path';

// Playwright compiles this spec as CommonJS, so __dirname is available natively.
const PNG_FIXTURE = path.join(__dirname, 'fixtures', 'pixel.png');
const TXT_FIXTURE = path.join(__dirname, 'fixtures', 'not-an-image.txt');

// The backend API base, host-reachable, for tests that assert raw HTTP status
// codes / headers directly (the generated client abstracts those away). The SPA
// itself talks to this same origin from the browser.
const API_BASE = process.env['API_BASE'] ?? 'http://localhost:8088/api';
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

  // The error-name element should appear with a validation message.
  await expect(page.locator('[data-testid="error-name"]')).toBeVisible({ timeout: 10_000 });
  const errorText = await page.locator('[data-testid="error-name"]').textContent();
  expect(errorText).not.toBeNull();
  expect(errorText!.length).toBeGreaterThan(0);
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

  // The pet starts with exactly one seeded photo URL (the create-form default).
  const before = await page.locator('[data-testid="detail-photo-urls"]').textContent();
  expect(before).toBeTruthy();

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
  expect(withKey.ok()).toBe(true);
  expect(withKey.status()).toBe(201);
  const bodyJson = await withKey.json();
  expect(bodyJson.message).toContain('Image uploaded');

  // And the same X-API-Key path works through the SPA (the generated client
  // forwards the per-call apiKey config as the X-API-Key header).
  await createPetAndOpenDetail(page, 'E2E-UploadAuth');
  await page.setInputFiles('[data-testid="upload-file-input"]', PNG_FIXTURE);
  await page.click('[data-testid="upload-submit"]');
  await expect(page.locator('[data-testid="upload-status"]')).toBeVisible({ timeout: 10_000 });
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
  expect(upload.ok()).toBe(true);
  const message: string = (await upload.json()).message;

  // Pull the recorded /storage/uploads/<file>.png URL out of the echoed message.
  const match = message.match(/\/storage\/uploads\/[^\s)]+\.png/);
  expect(match).not.toBeNull();
  const storedUrl = match![0];

  // The public storage symlink (php artisan storage:link, run at container
  // start) makes that URL serve the actual bytes.
  const origin = API_BASE.replace(/\/api$/, '');
  const img = await request.get(`${origin}${storedUrl}`);
  expect(img.status()).toBe(200);
  expect(img.headers()['content-type']).toContain('image/png');
  const served = await img.body();
  expect(served.length).toBe(png.length); // non-empty, same size as the uploaded pixel
  expect(Buffer.compare(served, png)).toBe(0); // byte-identical to what we uploaded
});
