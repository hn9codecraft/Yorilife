import { NAV_ITEMS } from '../src/config/navigation.js'
import { USERS, chooseOption, clearOption, expect, reseed, signIn, test } from './helpers.js'

test.beforeAll(() => reseed())

test('every Sprint 2 route still renders behind authentication', async ({ page }) => {
  test.setTimeout(120_000)
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

test('a saved headquarter stays after refresh and is visible to another user', async ({ page }) => {
  await signIn(page, USERS.admin, { path: '/login?next=%2Fsales-office%2Fheadquarter-list' })
  await expect(page).toHaveURL('/sales-office/headquarter-list')
  await expect(page.locator('h1.page-header__title')).toHaveText('Headquarter List')
  await page.getByRole('button', { name: 'Add headquarter' }).click()
  let locationModal = page.locator('.modal.show')
  await chooseOption(locationModal, 'State', 'Gujarat')
  await locationModal.getByLabel('Headquarter Name').fill('Saved HQ')
  await locationModal.getByLabel('Headquarter Code').fill('HQ-001')
  await locationModal.getByLabel('Address').fill('12 Demo Estate')
  await locationModal.getByLabel('Pincode').fill('380001')
  await locationModal.getByLabel('Phone Number').fill('9000000199')
  await locationModal.getByLabel('Email').fill('saved-hq@example.test')
  await locationModal.getByRole('button', { name: 'Save' }).click()
  const locationTable = page.getByRole('table', { name: 'Headquarters' })
  await expect(locationTable.locator('thead th')).toHaveText([
    '', 'Headquarter Name', 'State', 'Headquarter Code', 'Address', 'Pincode', 'Phone Number', 'Email', 'Actions',
  ])
  await expect(locationTable.locator('tbody tr', { hasText: 'Saved HQ' })).toContainText('Gujarat')
  await expect(locationTable.locator('tbody tr', { hasText: 'Saved HQ' })).toContainText('HQ-001')
  await expect(locationTable.locator('tbody tr', { hasText: 'Saved HQ' })).toContainText('saved-hq@example.test')
  await page.getByRole('button', { name: 'Add headquarter' }).click()
  locationModal = page.locator('.modal.show')
  await chooseOption(locationModal, 'State', 'Bihar')
  await locationModal.getByLabel('Headquarter Name').fill('Other HQ')
  await locationModal.getByLabel('Headquarter Code').fill('HQ-002')
  await locationModal.getByLabel('Address').fill('99 Other Road')
  await locationModal.getByLabel('Pincode').fill('800001')
  await locationModal.getByLabel('Phone Number').fill('9000000198')
  await locationModal.getByLabel('Email').fill('other-hq@example.test')
  await locationModal.getByRole('button', { name: 'Save' }).click()
  const hqFilters = page.locator('.toolbar')
  await chooseOption(hqFilters, 'State', 'Gujarat')
  await expect(locationTable.locator('tbody tr', { hasText: 'Saved HQ' })).toHaveCount(1)
  await expect(locationTable.locator('tbody tr', { hasText: 'Other HQ' })).toHaveCount(0)
  await clearOption(hqFilters, 'State')
  await expect(locationTable.locator('tbody tr', { hasText: 'Other HQ' })).toContainText('HQ-002')
  await page.reload()
  await expect(locationTable.locator('tbody tr', { hasText: 'Saved HQ' })).toHaveCount(1)

  await page.getByRole('button', { name: /Account menu/ }).click()
  await page.getByRole('button', { name: 'Sign out' }).click()
  await signIn(page, USERS.sales, { path: '/login?next=%2Fsales-office%2Fheadquarter-list' })
  await expect(page.locator('tbody tr', { hasText: 'Saved HQ' })).toHaveCount(1)
  await expect(page.getByRole('button', { name: 'Add headquarter' })).toHaveCount(0)
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

test('sales office filter menus use checkboxes and stay on screen', async ({ page }) => {
  await signIn(page, USERS.admin, { path: '/login?next=%2Fsales-office%2Fstockists' })
  await expect(page).toHaveURL('/sales-office/stockists')

  for (const width of [1280, 768, 390]) {
    await page.setViewportSize({ width, height: 800 })
    await page.getByRole('button', { name: 'Filter list' }).click()
    const filter = page.getByRole('dialog', { name: 'Filter list' })
    await expect(filter.locator('select')).toHaveCount(0)
    const position = await filter.evaluate((element) => getComputedStyle(element).position)
    expect(position).toBe('absolute')
    const state = filter.getByRole('button', { name: 'State: Select state' })
    await expect(filter.getByRole('button', { name: 'Reports To: Select manager' })).toHaveCount(0)
    const clear = filter.getByRole('button', { name: 'Clear Filters' })
    const apply = filter.getByRole('button', { name: 'Apply' })
    await expect(state).toBeVisible()
    const stateBox = await state.boundingBox()
    for (const box of [stateBox, await clear.boundingBox(), await apply.boundingBox()]) {
      expect(box.x).toBeGreaterThanOrEqual(-1)
      expect(box.x + box.width).toBeLessThanOrEqual(width + 1)
      expect(box.y).toBeGreaterThanOrEqual(-1)
      expect(box.y + box.height).toBeLessThanOrEqual(800 + 1)
    }
    await state.click()
    const menu = filter.locator('.list-filter__menu')
    await expect(menu.getByRole('checkbox', { name: 'Gujarat' })).toBeVisible()
    const openState = await state.boundingBox()
    const menuBox = await menu.boundingBox()
    expect(menuBox.y).toBeGreaterThanOrEqual(openState.y + openState.height - 2)
    expect(menuBox.y + menuBox.height).toBeLessThanOrEqual(800 + 1)
    expect(menuBox.x).toBeGreaterThanOrEqual(-1)
    expect(menuBox.x + menuBox.width).toBeLessThanOrEqual(width + 1)
    await page.screenshot({ path: `test-results/screens/filter-${width}.png` })
    await page.keyboard.press('Escape')
    await expect(menu).toBeHidden()
    await expect(filter).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(filter).toBeHidden()
  }
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
