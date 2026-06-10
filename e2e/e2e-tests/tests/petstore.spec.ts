import { test, expect, type Page } from '@playwright/test';

/**
 * Petstore end-to-end suite.
 *
 * These tests drive the SPA at http://localhost:8080 in headless Chromium.
 * Every assertion exercises a full round trip:
 *   browser -> SPA -> generated openapi-zod-ts client -> real HTTP ->
 *   generated Laravel backend (via openapi-laravel)
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
 *   detail-close,
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
