import { USERS, expect, reseed, signIn, test } from './helpers.js'

// Approved left-sidebar order and grouping (sidebar update request).
const TOP_LEVEL = ['Dashboard', 'Products', 'Sales Management', 'Sales Office', 'Application Details', 'Announcements', 'System & Audit']

const topLevelLabels = (page) => page.locator('.sidebar-nav > li > :is(button, a) .sidebar-group__label').allInnerTexts()
const group = (page, label) => page.locator('.sidebar-nav > li', { has: page.locator(':scope > button .sidebar-group__label', { hasText: label }) })
const subgroup = (page, label) => page.locator('.sidebar-subgroup', { has: page.locator(':scope > button', { hasText: label }) })

test.beforeAll(() => reseed())

test.beforeEach(async ({ page }) => {
  await signIn(page, USERS.admin)
  await expect(page).toHaveURL('/dashboard')
})

test('top-level menus appear in exactly the approved order', async ({ page }) => {
  expect(await topLevelLabels(page)).toEqual(TOP_LEVEL)
})

test('Dashboard is a single link; Announcements is a menu for admins', async ({ page }) => {
  const link = page.locator('.sidebar-nav > li > a.sidebar-toplink', { hasText: 'Dashboard' })
  await expect(link).toHaveAttribute('href', '/dashboard')
  await expect(link).not.toHaveAttribute('aria-expanded', /.*/)
  await expect(group(page, 'Announcements').locator('.sidebar-link')).toHaveText(['All Announcements', 'New Announcement', 'Templates', 'Employees'])
  // Headquarters Dashboard was removed.
  await expect(page.locator('.app-sidebar').getByText('Headquarters Dashboard')).toHaveCount(0)
  await expect(page.locator('.app-sidebar').getByText('Overview', { exact: true })).toHaveCount(0)
})

test('Reports is removed; Data Import stays nested in Sales Office, not top-level', async ({ page }) => {
  const sales = group(page, 'Sales Management')
  await expect(sales.locator(':scope > .sidebar-group__panel .sidebar-subgroup > button', { hasText: 'Reports' })).toHaveCount(0)
  await expect(sales.locator(':scope > .sidebar-group__panel .sidebar-subgroup > button', { hasText: 'Data Import' })).toHaveCount(0)
  const office = group(page, 'Sales Office')
  await expect(office.locator(':scope > .sidebar-group__panel .sidebar-subgroup > button', { hasText: 'Data Import' })).toHaveCount(1)
  expect(await topLevelLabels(page)).not.toContain('Reports')
  expect(await topLevelLabels(page)).not.toContain('Data Import')

  await expect(page.locator('.app-sidebar').getByText('Reports', { exact: true })).toHaveCount(0)
  await expect(page.locator('.app-sidebar a[href^="/reports/"]')).toHaveCount(0)
  const importLinks = subgroup(page, 'Data Import').locator('.sidebar-link')
  await expect(importLinks).toHaveText(['Import Data', 'Import History'])
})

test('CSV Export is absent and Data Import is reachable from the Sales Office menu', async ({ page }) => {
  await expect(page.locator('.app-sidebar').getByText(/CSV Export/i)).toHaveCount(0)
  await expect(page.locator('.app-sidebar').getByText(/export/i)).toHaveCount(0)

  await page.goto('/imports/data')
  await expect(page.locator('h1.page-header__title')).toHaveText('Import Data')
  await expect(page.locator('.breadcrumb-item')).toHaveText(['Dashboard', 'Sales Office', 'Data Import', 'Import Data'])
  await expect(page.getByLabel('Excel file')).toBeAttached()
})

test('group contents are preserved (Products, Sales Office, Application Details, System & Audit)', async ({ page }) => {
  const links = (label) => group(page, label).locator(':scope > .sidebar-group__panel > .sidebar-group__list > li > .sidebar-link')
  await expect(links('Products')).toHaveText(['Product Group Categories', 'Product Categories', 'Product Tags & Badges', 'Product List', 'Master Product Sheet', 'Deleted Products', 'Product Change History'])
  await expect(links('Sales Management')).toHaveText(['Stockist Sales', 'Monthly Sales', 'Sales by Headquarters', 'Sales by Stockist', 'Sales Calculation History'])
  await expect(links('Sales Office')).toHaveText(['Stockist Entry', 'Headquarters', '1st Line Manager', '2nd Line Manager', '3rd Line Manager', 'Location'])
  await expect(group(page, 'Sales Office').getByRole('link', { name: 'Sales Office Hierarchy' })).toHaveCount(0)
  await expect(page.locator('.sidebar-nav > li > a.sidebar-toplink', { hasText: 'Location' })).toHaveCount(0)
  await page.goto('/sales-office/locations')
  await expect(page.locator('h1.page-header__title')).toHaveText('Location')
  await expect(page.getByRole('table', { name: 'States' }).locator('thead th')).toHaveText(['State Name', 'Location Code', 'Actions'])
  await expect(page.getByRole('button', { name: 'Add location headquarters' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Add Headquarters' })).toHaveCount(0)
  await expect(page.getByRole('button', { name: 'CSV template' })).toHaveCount(0)
  await expect(page.getByRole('button', { name: 'Import CSV' })).toHaveCount(0)
  await expect(page.getByRole('link', { name: 'Headquarters', exact: true })).toHaveCount(1)
  await page.getByRole('button', { name: 'Edit Location Gujarat' }).click()
  await expect(page.locator('.modal.show').getByLabel('Headquarters')).toHaveCount(0)
  await page.locator('.modal.show').getByRole('button', { name: 'Cancel' }).click()
  await expect(page.locator('tbody tr', { hasText: 'Gujarat' })).toContainText('GUJ')
  await expect(page.getByRole('button', { name: 'Edit Location Gujarat' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Delete Location Gujarat' })).toBeVisible()
  await expect(links('Application Details')).toHaveText(['Sales Entry Overview', 'Submission Status', 'Sales Entry History'])
  await expect(links('System & Audit')).toHaveText(['Users', 'Audit Logs', 'Change History', 'Settings'])
})

test('a nested Data Import page activates and expands Sales Office › Data Import, with matching breadcrumbs', async ({ page }) => {
  await page.goto('/imports/data')
  await expect(page.locator('h1.page-header__title')).toHaveText('Import Data')
  await expect(page.locator('.breadcrumb-item')).toHaveText(['Dashboard', 'Sales Office', 'Data Import', 'Import Data'])
  await expect(page.locator('.app-header__context')).toHaveCount(0)
  await expect(page.getByRole('combobox', { name: 'Search' })).toBeVisible()
  await expect(page.getByRole('combobox', { name: 'Search' })).toHaveAttribute('placeholder', 'Search anything...')
  await expect(page.getByRole('button', { name: 'Notifications (coming soon)' })).toBeVisible()
  await expect(page.getByRole('button', { name: /Account menu for/ })).toBeVisible()

  const office = group(page, 'Sales Office')
  await expect(office).toHaveClass(/is-open/)
  await expect(office).toHaveClass(/is-active/)
  await expect(office.locator(':scope > button')).toHaveAttribute('aria-expanded', 'true')
  const dataImport = subgroup(page, 'Data Import')
  await expect(dataImport).toHaveClass(/is-open/)
  await expect(dataImport).toHaveClass(/is-active/)
  await expect(dataImport.locator(':scope > button')).toHaveAttribute('aria-expanded', 'true')
  await expect(page.locator('.app-sidebar [aria-current="page"]')).toHaveText('Import Data')
})

test('nested Data Import expands and collapses under Sales Office, with mouse and keyboard', async ({ page }) => {
  const officeToggle = group(page, 'Sales Office').locator(':scope > button')
  await officeToggle.click()
  await expect(officeToggle).toHaveAttribute('aria-expanded', 'true')

  const importToggle = subgroup(page, 'Data Import').locator(':scope > button')
  await expect(importToggle).toHaveAttribute('aria-expanded', 'false')
  await importToggle.focus()
  await page.keyboard.press('Enter')
  await expect(importToggle).toHaveAttribute('aria-expanded', 'true')
  await expect(subgroup(page, 'Data Import').locator('.sidebar-link').first()).toBeVisible()

  await page.keyboard.press('Tab')
  await expect(page.locator(':focus')).toHaveText('Import Data')
  await page.keyboard.press('Enter')
  await expect(page).toHaveURL('/imports/data')

  await importToggle.click()
  await expect(importToggle).toHaveAttribute('aria-expanded', 'false')
  await expect(subgroup(page, 'Data Import').locator('.sidebar-link').first()).toBeHidden()
  await officeToggle.click()
  await expect(officeToggle).toHaveAttribute('aria-expanded', 'false')
})

test('opening one top-level group closes the previous; Data Import closes with its parent', async ({ page }) => {
  const salesToggle = group(page, 'Sales Management').locator(':scope > button')
  const officeToggle = group(page, 'Sales Office').locator(':scope > button')
  const importToggle = subgroup(page, 'Data Import').locator(':scope > button')

  await salesToggle.click()
  await expect(salesToggle).toHaveAttribute('aria-expanded', 'true')
  await expect(officeToggle).toHaveAttribute('aria-expanded', 'false')

  await officeToggle.click()
  await expect(officeToggle).toHaveAttribute('aria-expanded', 'true')
  await expect(salesToggle).toHaveAttribute('aria-expanded', 'false')

  await importToggle.click()
  await expect(importToggle).toHaveAttribute('aria-expanded', 'true')
  await expect(officeToggle).toHaveAttribute('aria-expanded', 'true')

  await salesToggle.click()
  await expect(salesToggle).toHaveAttribute('aria-expanded', 'true')
  await expect(officeToggle).toHaveAttribute('aria-expanded', 'false')
  await expect(importToggle).toHaveAttribute('aria-expanded', 'false')

  // Landing on a nested page expands only that branch; navigating away collapses Data Import.
  await page.goto('/imports/data')
  await expect(officeToggle).toHaveAttribute('aria-expanded', 'true')
  await expect(importToggle).toHaveAttribute('aria-expanded', 'true')
  await page.goto('/sales/monthly')
  await expect(salesToggle).toHaveAttribute('aria-expanded', 'true')
  await expect(officeToggle).toHaveAttribute('aria-expanded', 'false')
  await expect(importToggle).toHaveAttribute('aria-expanded', 'false')
})

test('Back/Forward update the active item across top-level links and nested pages', async ({ page }) => {
  const active = page.locator('.app-sidebar [aria-current="page"]')
  await group(page, 'Announcements').locator(':scope > button').click()
  await page.locator('.app-sidebar a[href="/announcements/list"]').click()
  await expect(active).toHaveText('All Announcements')
  await expect(page.locator('h1.page-header__title')).toHaveText('All Announcements')
  await page.locator('.app-sidebar a[href="/sales/monthly"]').evaluate((a) => a.click())
  await expect(active).toHaveText('Monthly Sales')

  await page.goBack()
  await expect(page).toHaveURL('/announcements/list')
  await expect(active).toHaveText('All Announcements')
  await page.goBack()
  await expect(page).toHaveURL('/dashboard')
  await expect(active).toHaveText('Dashboard')
  await expect(page.locator('.breadcrumb-item')).toHaveText(['Dashboard'])
  await page.goForward()
  await page.goForward()
  await expect(active).toHaveText('Monthly Sales')
})

test('group URLs redirect to their first page; removed Headquarters Dashboard is a 404', async ({ page }) => {
  for (const [from, to] of [['/sales', '/sales/stockist-sales'], ['/imports', '/imports/data'], ['/sales-app', '/sales-app/overview'], ['/products', '/products/group-categories'], ['/announcements', '/announcements/list']]) {
    await page.goto(from)
    await expect(page, from).toHaveURL(to)
  }
  await page.goto('/dashboard/headquarters')
  await expect(page.locator('h1.page-header__title')).toHaveText('Page not found')
  for (const gone of ['/reports', '/reports/headquarters', '/reports/stockists', '/reports/monthly-sales', '/reports/product-performance']) {
    await page.goto(gone)
    await expect(page.locator('h1.page-header__title'), gone).toHaveText('Page not found')
  }
})

test('collapsed desktop rail shows the 7 top-level icons; top-level links work there', async ({ page }) => {
  await page.getByRole('button', { name: 'Collapse sidebar' }).click()
  await expect(page.locator('.app-shell')).toHaveClass(/is-sidebar-collapsed/)
  await expect(page.locator('.sidebar-nav > li')).toHaveCount(7)
  await expect(page.locator('.sidebar-group__panel').first()).toBeHidden()
  await expect(page.locator('.sidebar-toplink.active')).toHaveAttribute('title', 'Dashboard')

  await page.goto('/sales/monthly')
  await expect(page.locator('.app-shell')).toHaveClass(/is-sidebar-collapsed/)
  await page.locator('.sidebar-toplink', { hasText: 'Dashboard' }).click()
  await expect(page).toHaveURL('/dashboard')
  await expect(page.locator('.app-shell')).toHaveClass(/is-sidebar-collapsed/)

  // Clicking a group icon in the rail expands the sidebar and opens it.
  await group(page, 'Sales Management').locator(':scope > button').click()
  await expect(page.locator('.app-shell')).not.toHaveClass(/is-sidebar-collapsed/)
  await expect(group(page, 'Sales Management')).toHaveClass(/is-open/)
})

test('mobile drawer shows the same menu; choosing a nested page navigates and closes it', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 800 })
  await page.getByRole('button', { name: 'Open navigation menu' }).click()
  expect(await topLevelLabels(page)).toEqual(TOP_LEVEL)

  await group(page, 'Sales Office').locator(':scope > button').click()
  await subgroup(page, 'Data Import').locator(':scope > button').click()
  await subgroup(page, 'Data Import').getByRole('link', { name: 'Import History' }).click()
  await expect(page).toHaveURL('/imports/history')
  await expect(page.locator('.app-shell')).not.toHaveClass(/is-sidebar-open/)

  for (const width of [320, 375, 768]) {
    await page.setViewportSize({ width, height: 800 })
    await page.getByRole('button', { name: 'Open navigation menu' }).click()
    await group(page, 'Sales Management').locator(':scope > button').evaluate((b) => b.getAttribute('aria-expanded') === 'false' && b.click())
    await group(page, 'Sales Office').locator(':scope > button').evaluate((b) => b.getAttribute('aria-expanded') === 'false' && b.click())
    await subgroup(page, 'Data Import').locator(':scope > button').evaluate((b) => b.getAttribute('aria-expanded') === 'false' && b.click())
    await page.waitForTimeout(300)
    const problems = await page.evaluate(() => {
      const clipped = [...document.querySelectorAll('.sidebar-link, .sidebar-group__label, .sidebar-subgroup__label')]
        .filter((el) => el.scrollWidth > el.clientWidth + 1)
        .map((el) => el.textContent)
      const overflow = document.documentElement.scrollWidth > document.documentElement.clientWidth
      return { clipped, overflow }
    })
    expect(problems, `${width}px`).toEqual({ clipped: [], overflow: false })
    await page.keyboard.press('Escape')
  }
  await page.screenshot({ path: 'test-results/screens/sidebar-mobile-375.png' })
})

test('desktop: expanded nested menu has no clipped labels', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1000 })
  await page.goto('/imports/history')
  await page.waitForTimeout(300)
  const clipped = await page.evaluate(() =>
    [...document.querySelectorAll('.sidebar-link, .sidebar-group__label, .sidebar-subgroup__label')]
      .filter((el) => el.offsetParent && el.scrollWidth > el.clientWidth + 1)
      .map((el) => el.textContent),
  )
  expect(clipped).toEqual([])
  await page.screenshot({ path: 'test-results/screens/sidebar-desktop-1440.png' })
})
