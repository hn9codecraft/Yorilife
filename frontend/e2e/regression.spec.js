import { NAV_ITEMS } from '../src/config/navigation.js'
import { USERS, expect, reseed, signIn, test } from './helpers.js'

test.beforeAll(() => reseed())

test('every Sprint 2 route still renders behind authentication', async ({ page }) => {
  await signIn(page, USERS.admin)
  await expect(page).toHaveURL('/dashboard')

  for (const item of NAV_ITEMS) {
    await page.goto(item.path)
    await expect(page.locator('h1.page-header__title'), item.path).toHaveText(item.label)
    await expect(page.locator('.app-sidebar [aria-current="page"]'), item.path).toHaveText(item.label)
  }

  await page.goto('/')
  await expect(page).toHaveURL('/dashboard')
  await page.goto('/no/such/page')
  await expect(page.locator('h1.page-header__title')).toHaveText('Page not found')
  await page.goto('/design-system')
  await expect(page.locator('h1.page-header__title')).toHaveText('Design System')
})

test('preview screens still work after sign-in and reset for a different user', async ({ page }) => {
  await signIn(page, USERS.admin, { path: '/login?next=%2Fsales-office%2Fheadquarters' })
  await page.getByRole('button', { name: 'Add headquarters' }).first().click()
  await page.locator('.modal.show').getByLabel('Headquarters name').fill('Preview HQ')
  await page.locator('.modal.show').getByRole('button', { name: 'Save' }).click()
  await expect(page.locator('tbody tr', { hasText: 'Preview HQ' })).toHaveCount(1)

  // Sign out and in as someone else: previous user's unsaved preview data is gone.
  await page.getByRole('button', { name: /Account menu/ }).click()
  await page.getByRole('button', { name: 'Sign out' }).click()
  await signIn(page, USERS.sales, { path: '/login?next=%2Fsales-office%2Fheadquarters' })
  await expect(page.getByText('No headquarters yet').first()).toBeVisible()
})

test('mobile drawer and desktop collapse still work for a signed-in user', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 800 })
  await signIn(page, USERS.sales)
  await page.getByRole('button', { name: 'Open navigation menu' }).click()
  await expect(page.getByRole('button', { name: 'Close navigation menu' })).toBeFocused()
  await page.keyboard.press('Escape')
  await expect(page.getByRole('button', { name: 'Open navigation menu' })).toBeFocused()

  await page.setViewportSize({ width: 1280, height: 800 })
  await page.getByRole('button', { name: 'Collapse sidebar' }).click()
  await expect(page.locator('.app-shell')).toHaveClass(/is-sidebar-collapsed/)
  await page.getByRole('button', { name: 'Expand sidebar' }).click()
})

for (const width of [320, 375, 576, 768, 1024, 1280, 1440]) {
  test(`no horizontal overflow at ${width}px (login, dashboard, users, account menu)`, async ({ page }) => {
    await page.setViewportSize({ width, height: 800 })
    const overflow = () => page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth)

    await page.goto('/login')
    await expect(page.getByRole('button', { name: 'Sign in' })).toBeVisible()
    expect(await overflow(), 'login').toBe(false)
    const card = await page.locator('.auth-card').boundingBox()
    expect(card.x).toBeGreaterThanOrEqual(0)
    expect(card.x + card.width).toBeLessThanOrEqual(width)

    await signIn(page, USERS.admin)
    await expect(page).toHaveURL('/dashboard')
    expect(await overflow(), 'dashboard').toBe(false)

    await page.goto('/system/users')
    await expect(page.locator('tbody tr').first()).toBeVisible()
    expect(await overflow(), 'users').toBe(false)

    await page.getByRole('button', { name: /Account menu/ }).click()
    const menu = await page.locator('.app-user-menu__menu').boundingBox()
    expect(menu.x).toBeGreaterThanOrEqual(0)
    expect(menu.x + menu.width).toBeLessThanOrEqual(width + 0.5)
    await page.screenshot({ path: `test-results/screens/users-${width}.png`, fullPage: true })
  })
}
