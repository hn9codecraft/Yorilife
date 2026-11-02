import { USERS, expect, reseed, signIn, test } from './helpers.js'

test.beforeAll(() => reseed())

test.beforeEach(async ({ page }) => {
  await signIn(page, USERS.admin)
  await expect(page).toHaveURL('/dashboard')
})

const searchBox = (page) => page.getByRole('combobox', { name: 'Search' })

test('Enter opens the highlighted page and leaves breadcrumbs on the page', async ({ page }) => {
  await expect(page.locator('main .search-field')).toHaveCount(0)
  const search = searchBox(page)
  await search.click()
  await expect(page.getByText('Type to search pages and records.')).toBeVisible()
  await search.fill('Stockist Reports')
  const option = page.getByRole('option', { name: /Stockist Reports/ })
  await expect(option).toBeVisible()
  await search.press('Enter')
  await expect(page).toHaveURL('/reports/stockists')
  await expect(page.locator('h1.page-header__title')).toHaveText('Stockist Reports')
  await expect(page.locator('.breadcrumb-item')).toHaveText(['Dashboard', 'Sales Management', 'Reports', 'Stockist Reports'])
  await expect(search).toHaveValue('')
})

test('a location record opens Location, Escape closes suggestions, and a miss is empty', async ({ page }) => {
  const search = searchBox(page)
  await search.fill('Gujarat')
  const option = page.getByRole('option', { name: /^Gujarat/ })
  await expect(option).toBeVisible()
  await search.press('Escape')
  await expect(option).toBeHidden()
  await search.press('Enter')
  await expect(page).toHaveURL('/sales-office/locations')
  await expect(page.locator('h1.page-header__title')).toHaveText('Location')
  await expect(page.locator('.breadcrumb-item').last()).toHaveText('Location')
  await expect(page.getByLabel('Search states')).toBeVisible()

  await search.fill('zzz-no-such-record')
  await expect(page.getByText('No matches for “zzz-no-such-record”.')).toBeVisible()
})

test('users are searchable only with users.view', async ({ page }) => {
  const search = searchBox(page)
  await search.fill('E2E Sales')
  await page.getByRole('option', { name: /E2E Sales User/ }).click()
  await expect(page).toHaveURL('/system/users')
  await expect(page.locator('h1.page-header__title')).toHaveText('Users')

  await page.getByRole('button', { name: /Account menu for/ }).click()
  await page.getByRole('button', { name: 'Sign out' }).click()
  await signIn(page, USERS.sales)
  await expect(page).toHaveURL('/dashboard')
  await searchBox(page).fill('Users')
  await expect(page.getByText('No matches for “Users”.')).toBeVisible()
  await expect(page.getByRole('option')).toHaveCount(0)
  await searchBox(page).fill('E2E Admin')
  await expect(page.getByText('No matches for “E2E Admin”.')).toBeVisible()
})

test('the search field stays inside the header on phone, tablet, and desktop', async ({ page }) => {
  const header = page.locator('.app-header__inner')
  for (const width of [390, 768, 1280]) {
    await page.setViewportSize({ width, height: 800 })
    const search = searchBox(page)
    await expect(search).toBeVisible()
    await expect(page.getByRole('button', { name: 'Notifications (coming soon)' })).toBeVisible()
    const bar = await header.boundingBox()
    const field = await search.boundingBox()
    expect(field.x).toBeGreaterThanOrEqual(bar.x - 1)
    expect(field.x + field.width).toBeLessThanOrEqual(bar.x + bar.width + 1)
  }
})
