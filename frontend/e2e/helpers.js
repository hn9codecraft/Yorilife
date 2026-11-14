import { test as base, expect } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { resolve } from 'node:path'

export const PASSWORD = process.env.E2E_PASSWORD
export const USERS = {
  owner: 'e2e-owner@example.test',
  admin: 'e2e-admin@example.test',
  sales: 'e2e-sales@example.test',
  disabled: 'e2e-disabled@example.test',
}

/** Reset the TEST database to the fixture users (see backend/tests/e2e-seed.php). */
export function reseed() {
  execFileSync(process.env.PHP_BIN ?? 'php', [resolve(import.meta.dirname, '../../backend/tests/e2e-seed.php')], {
    env: process.env,
    stdio: 'pipe',
  })
}

/** Signed-in JSON call. The browser session cookie is sent with the request. */
export async function api(page, method, path, body) {
  const headers = { Accept: 'application/json' }
  if (method !== 'GET' && method !== 'HEAD') {
    const me = await page.request.get('/api/auth/me')
    const meBody = await me.json()
    expect(me.ok(), JSON.stringify(meBody)).toBeTruthy()
    headers['X-CSRF-Token'] = meBody.data.csrfToken
  }
  const response = await page.request.fetch(path, { method, headers, data: body })
  const payload = await response.json()
  expect(response.ok(), `${method} ${path} ${response.status()} ${JSON.stringify(payload)}`).toBeTruthy()
  return payload.data
}

/** Create a 3rd → 2nd → 1st line in Gujarat so headquarters tests still have a parent. */
export async function seedManagerChain(page, names) {
  const locations = (await api(page, 'GET', '/api/locations')).locations
  const gujarat = locations.find((location) => location.name === 'Gujarat')
  expect(gujarat, 'Gujarat is seeded').toBeTruthy()
  const third = (await api(page, 'POST', '/api/managers', {
    name: names.third,
    code: '001',
    level: 3,
    email: `${names.third.toLowerCase().replace(/\s+/g, '-')}@example.test`,
    phone: '9000000181',
    stateIds: [gujarat.id],
  })).manager
  const second = (await api(page, 'POST', '/api/managers', {
    name: names.second,
    code: '',
    level: 2,
    parentType: 'manager3',
    parentId: third.id,
    stateIds: [gujarat.id],
  })).manager
  const first = (await api(page, 'POST', '/api/managers', {
    name: names.first,
    code: '',
    level: 1,
    parentType: 'manager2',
    parentId: second.id,
    stateIds: [gujarat.id],
  })).manager
  return { third, second, first }
}

export async function signIn(page, email, { password = PASSWORD, path = '/login' } = {}) {
  await page.goto(path)
  await page.getByLabel('Email address').fill(email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
}

/**
 * `test` with a `consoleErrors` fixture: every test fails if the page logs an
 * unexpected error. Chrome's "Failed to load resource" lines for deliberate
 * 401/403/422/429 API answers are expected and ignored (they're asserted on
 * directly in the tests).
 */
export const test = base.extend({
  consoleErrors: [
    async ({ page }, use) => {
      const errors = []
      page.on('pageerror', (error) => errors.push(String(error)))
      page.on('console', (message) => {
        if (message.type() !== 'error') return
        const text = message.text()
        if (/Failed to load resource: the server responded with a status of (401|403|422|429)/.test(text)) return
        if (message.location().url.endsWith('/favicon.ico')) return
        errors.push(text)
      })
      await use(errors)
      expect(errors, 'unexpected browser console errors').toEqual([])
    },
    { auto: true },
  ],
})

export { expect }
