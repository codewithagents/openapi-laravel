import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for the openapi-laravel cross-language e2e demo.
 *
 * The suite drives the SPA (http://localhost:8080) in headless Chromium,
 * which issues real fetch requests to the Laravel backend (http://localhost:8088/api)
 * via the generated openapi-zod-ts client. This proves the full round trip:
 * browser -> SPA -> generated TS client -> real HTTP -> generated Laravel backend.
 */
export default defineConfig({
  testDir: './tests',
  testMatch: '**/*.spec.ts',

  // Fail the suite if any test file has an error.
  fullyParallel: false,

  // Retry on CI, run clean locally.
  retries: process.env['CI'] ? 2 : 1,

  // Serial execution: the tests share state via the backend JSON store.
  workers: 1,

  reporter: [
    ['list'],
    ['html', { open: 'never', outputFolder: 'playwright-report' }],
  ],

  // Global timeout per test.
  timeout: 60_000,

  // Assertion timeout.
  expect: {
    timeout: 15_000,
  },

  use: {
    baseURL: 'http://localhost:8080',

    // Collect trace on first retry to help diagnose flaky failures.
    trace: 'on-first-retry',

    // Screenshot on failure.
    screenshot: 'only-on-failure',

    // Headless Chromium.
    headless: true,

    // Wait up to 30s for navigations and actions.
    navigationTimeout: 30_000,
    actionTimeout: 15_000,
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],

  // No webServer block: the compose stack is managed by run.sh or brought up
  // by the caller. The tests wait for the stack to be healthy before running.
});
