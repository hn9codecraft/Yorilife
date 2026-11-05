import { defineConfig } from '@playwright/test'
import { resolve } from 'node:path'

/*
 * End-to-end tests (npm run test:e2e).
 * Starts an isolated stack — nothing touches the development database:
 *   - PHP API on 127.0.0.1:8098 using the TEST database (yorilife_erp_test)
 *   - Vite on localhost:5174, proxying /api to that API
 * Requirements: MySQL running, backend/.env DB credentials, PHP 8.3
 * (set PHP_BIN if `php` is not on PATH) and Google Chrome installed.
 */

const PHP = process.env.PHP_BIN ?? 'php'
const backend = resolve(import.meta.dirname, '../backend')
// Test-only password for fixture users in the _test database.
process.env.E2E_PASSWORD ??= 'E2e-Only-Test-Password-1'

export default defineConfig({
  testDir: './e2e',
  timeout: 60_000,
  expect: { timeout: 10_000 },
  // One worker: specs share the test database and reseed it.
  workers: 1,
  fullyParallel: false,
  reporter: [['list']],
  use: {
    baseURL: 'http://localhost:5174',
    channel: 'chrome',
    trace: 'retain-on-failure',
  },
  webServer: [
    {
      command: `"${PHP}" -S 127.0.0.1:8098 -t "${backend}/public" "${backend}/public/index.php"`,
      url: 'http://127.0.0.1:8098/api/health',
      reuseExistingServer: false,
      // PHP's built-in server logs every connection to stderr; keep test output readable.
      stderr: 'ignore',
      env: {
        DB_DATABASE: process.env.TEST_DB_DATABASE ?? 'yorilife_erp_test',
        APP_ENV: 'testing',
        APP_DEBUG: 'false',
        APP_URL: 'http://127.0.0.1:8098',
        CORS_ALLOWED_ORIGINS: 'http://localhost:5174',
        SESSION_SECURE_COOKIE: 'false',
        SESSION_SAVE_PATH: `${backend}/storage/e2e-sessions`,
        IMPORT_STORAGE_PATH: `${backend}/storage/e2e-imports`,
      },
    },
    {
      command: 'npx vite --port 5174 --strictPort',
      url: 'http://localhost:5174/login',
      reuseExistingServer: false,
      env: { VITE_API_PROXY_TARGET: 'http://127.0.0.1:8098' },
    },
  ],
})
