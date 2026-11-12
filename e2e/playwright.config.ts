import { defineConfig, devices } from '@playwright/test'

/**
 * Runs inside the Playwright container of docker-compose.e2e.yml against the throwaway stand.
 * One worker: the tests share one database (fixtures are loaded once per run), and each test uses its own data.
 */
export default defineConfig({
  testDir: './tests',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 60_000,
  expect: { timeout: 10_000 },
  reporter: [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]],
  outputDir: 'test-results',
  use: {
    ...devices['Desktop Chrome'],
    // localhost: the Playwright container shares the frontend's network (a secure context, see compose).
    baseURL: process.env.BASE_URL ?? 'http://localhost:3000',
    viewport: { width: 1440, height: 900 },
    locale: 'ru-RU',
    timezoneId: 'Europe/Madrid',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
})
