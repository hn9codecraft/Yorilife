import { USERS, expect, reseed, signIn, test } from './helpers.js'

test.beforeEach(() => reseed())

const row = (page, text) => page.locator('tbody tr', { hasText: text })

// Wait until the Bootstrap modal has finished opening (focus moved inside it).
async function modalReady(page) {
  await page.waitForFunction(() => document.activeElement?.closest('.modal.show'))
  return page.locator('.modal.show')
}

test('users without permission see no Users menu, get an access page and a 403 from the API', async ({ page }) => {
  await signIn(page, USERS.sales)
  await expect(page).toHaveURL('/dashboard')
  await expect(page.locator('.app-sidebar a[href="/system/users"]')).toHaveCount(0)

  await page.goto('/system/users')
  await expect(page.getByRole('heading', { name: 'You don’t have access to this page' })).toBeVisible()

  const status = await page.evaluate(async () => (await fetch('/api/users')).status)
  expect(status).toBe(403)
})

test('admin manages users: list, view, create, edit, disable and reactivate', async ({ page, browser }) => {
  await signIn(page, USERS.admin)
  await page.locator('.app-sidebar a[href="/system/users"]').evaluate((a) => a.click())
  await expect(page.getByRole('heading', { level: 1, name: 'Users' })).toBeVisible()
  await expect(page.locator('tbody tr')).toHaveCount(4)

  // Own row: no disable; higher-ranked Super Admin: view only.
  await expect(row(page, 'E2E Admin').getByRole('button', { name: /Disable/ })).toHaveCount(0)
  await expect(row(page, 'E2E Super Admin').getByRole('button', { name: /Edit|Disable/ })).toHaveCount(0)

  // View details
  await row(page, 'E2E Sales User').getByRole('button', { name: /View/ }).click()
  const details = await modalReady(page)
  await expect(details.getByText(USERS.sales)).toBeVisible()
  await expect(details.getByText('Sales User', { exact: true })).toBeVisible()
  await page.keyboard.press('Escape')
  await expect(details).toHaveCount(0)

  // Create — only roles below Admin are offered
  await page.getByRole('button', { name: 'Add user' }).click()
  const modal = await modalReady(page)
  await expect(modal.getByLabel('Full name')).toBeFocused()
  await expect(modal.getByLabel('Role').locator('option')).toHaveText(['Choose a role', 'Sales Manager', 'Sales User'])

  await modal.getByRole('button', { name: 'Create user' }).click()
  await expect(modal.getByText('Enter a name.')).toBeVisible()

  await modal.getByLabel('Full name').fill('Priya Nair')
  await modal.getByLabel('Email (used to sign in)').fill('Priya.Nair@Example.test')
  await modal.getByLabel('Role').selectOption({ label: 'Sales Manager' })
  await modal.getByLabel('Initial password').fill('short')
  await modal.getByRole('button', { name: 'Create user' }).click()
  await expect(modal.getByText('Use at least 12 characters.')).toBeVisible()
  await modal.getByLabel('Initial password').fill('Priya-Initial-Pass-01')
  await modal.getByRole('button', { name: 'Create user' }).click()
  await expect(page.locator('.modal.show')).toHaveCount(0)
  await expect(page.getByRole('status').filter({ hasText: 'Priya Nair was added.' })).toBeVisible()
  await expect(row(page, 'Priya Nair')).toContainText('priya.nair@example.test')
  await expect(row(page, 'Priya Nair')).toContainText('Sales Manager')

  // Duplicate email is reported on the field (server-side check)
  await page.getByRole('button', { name: 'Add user' }).click()
  await modal.getByLabel('Full name').fill('Someone Else')
  await modal.getByLabel('Email (used to sign in)').fill(USERS.sales)
  await modal.getByLabel('Role').selectOption({ label: 'Sales User' })
  await modal.getByLabel('Initial password').fill('Another-Long-Pass-1')
  await modal.getByRole('button', { name: 'Create user' }).click()
  await expect(modal.getByText('A user with this email already exists.')).toBeVisible()
  await page.keyboard.press('Escape')

  // Edit
  await row(page, 'Priya Nair').getByRole('button', { name: /Edit/ }).click()
  await modal.getByLabel('Full name').fill('Priya N.')
  await modal.getByLabel('Role').selectOption({ label: 'Sales User' })
  await modal.getByRole('button', { name: 'Save' }).click()
  await expect(row(page, 'Priya N.')).toContainText('Sales User')

  // The new user can sign in
  const other = await browser.newContext()
  const otherPage = await other.newPage()
  await signIn(otherPage, 'priya.nair@example.test', { password: 'Priya-Initial-Pass-01' })
  await expect(otherPage).toHaveURL('/dashboard')

  // Disable → their open session ends at the next request; they can't sign in again
  await row(page, 'Priya N.').getByRole('button', { name: /Disable/ }).click()
  await page.locator('.modal.show').getByRole('button', { name: 'Disable' }).click()
  await expect(row(page, 'Priya N.')).toContainText('Disabled')
  await otherPage.reload()
  await expect(otherPage).toHaveURL(/\/login/)
  await signIn(otherPage, 'priya.nair@example.test', { password: 'Priya-Initial-Pass-01' })
  await expect(otherPage.getByRole('alert')).toHaveText('This account is disabled. Contact an administrator.')
  await other.close()

  // Reactivate
  await row(page, 'Priya N.').getByRole('button', { name: /Activate/ }).click()
  await page.locator('.modal.show').getByRole('button', { name: 'Activate' }).click()
  await expect(row(page, 'Priya N.')).toContainText('Active')
})

test('own role is locked in the edit form and the API refuses self role changes', async ({ page }) => {
  await signIn(page, USERS.admin, { path: '/login?next=%2Fsystem%2Fusers' })
  await expect(page).toHaveURL('/system/users')
  await row(page, 'E2E Admin').getByRole('button', { name: /Edit/ }).click()
  const modal = page.locator('.modal.show')
  await expect(modal.getByLabel('Role')).toBeDisabled()
  await expect(modal.getByText('You can’t change your own role.')).toBeVisible()
  await page.keyboard.press('Escape')

  // Even a crafted request is rejected by the server.
  const result = await page.evaluate(async () => {
    const me = await (await fetch('/api/auth/me')).json()
    const response = await fetch(`/api/users/${me.data.user.id}`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': me.data.csrfToken },
      body: JSON.stringify({ role: 'super_admin' }),
    })
    return { status: response.status, code: (await response.json()).error.code }
  })
  expect(result).toEqual({ status: 403, code: 'cannot_change_own_role' })
})

test('super admin can assign every role', async ({ page }) => {
  await signIn(page, USERS.owner, { path: '/login?next=%2Fsystem%2Fusers' })
  await page.getByRole('button', { name: 'Add user' }).click()
  const modal = await modalReady(page)
  await expect(modal.getByLabel('Role').locator('option')).toHaveText(['Choose a role', 'Super Admin', 'Admin', 'Sales Manager', 'Sales User'])
})
