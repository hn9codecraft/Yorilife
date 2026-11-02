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

test('Dashboard and Announcements are single links without submenus', async ({ page }) => {
  for (const [label, path] of [['Dashboard', '/dashboard'], ['Announcements', '/announcements']]) {
    const link = page.locator('.sidebar-nav > li > a.sidebar-toplink', { hasText: label })
    await expect(link).toHaveAttribute('href', path)
    await expect(link).not.toHaveAttribute('aria-expanded', /.*/)
  }
  // Headquarters Dashboard was removed.
  await expect(page.locator('.app-sidebar').getByText('Headquarters Dashboard')).toHaveCount(0)
  await expect(page.locator('.app-sidebar').getByText('Overview', { exact: true })).toHaveCount(0)
})

test('Reports and CSV Import are nested inside Sales Management, not top-level', async ({ page }) => {
  const sales = group(page, 'Sales Management')
  await expect(sales.locator(':scope > .sidebar-group__panel .sidebar-subgroup > button', { hasText: 'Reports' })).toHaveCount(1)
  await expect(sales.locator(':scope > .sidebar-group__panel .sidebar-subgroup > button', { hasText: 'CSV Import' })).toHaveCount(1)
  expect(await topLevelLabels(page)).not.toContain('Reports')
  expect(await topLevelLabels(page)).not.toContain('CSV Import')

  const reportLinks = subgroup(page, 'Reports').locator('.sidebar-link')
  await expect(reportLinks).toHaveText(['Headquarters Reports', 'Stockist Reports', 'Monthly Sales Reports', 'Product Performance Reports'])
  await expect(page.locator('.app-sidebar a[href^="/reports/"]')).toHaveCount(4) // no duplicates
})

test('CSV Export is absent and CSV Import is still reachable', async ({ page }) => {
  await expect(page.locator('.app-sidebar').getByText(/CSV Export/i)).toHaveCount(0)
  await expect(page.locator('.app-sidebar').getByText(/export/i)).toHaveCount(0)

  await page.goto('/imports/data')
  await expect(page.locator('h1.page-header__title')).toHaveText('Import Data')
  await expect(page.locator('.breadcrumb-item')).toHaveText(['Dashboard', 'Sales Management', 'CSV Import', 'Import Data'])
  await expect(page.getByLabel('CSV file')).toBeVisible()
  await page.goto('/sales/stockist-sales')
  await page.getByRole('link', { name: /Import CSV/ }).click()
  await expect(page).toHaveURL('/imports/data?type=sales')
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

test('a nested report page activates and expands Sales Management › Reports, with matching breadcrumbs', async ({ page }) => {
  await page.goto('/reports/stockists')
  await expect(page.locator('h1.page-header__title')).toHaveText('Stockist Reports')
  await expect(page.locator('.breadcrumb-item')).toHaveText(['Dashboard', 'Sales Management', 'Reports', 'Stockist Reports'])
  await expect(page.locator('.app-header__context')).toHaveCount(0)
  await expect(page.getByRole('combobox', { name: 'Search' })).toBeVisible()
  await expect(page.getByRole('combobox', { name: 'Search' })).toHaveAttribute('placeholder', 'Search anything...')
  await expect(page.getByRole('button', { name: 'Notifications (coming soon)' })).toBeVisible()
  await expect(page.getByRole('button', { name: /Account menu for/ })).toBeVisible()

  const sales = group(page, 'Sales Management')
  await expect(sales).toHaveClass(/is-open/)
  await expect(sales).toHaveClass(/is-active/)
  await expect(sales.locator(':scope > button')).toHaveAttribute('aria-expanded', 'true')
  const reports = subgroup(page, 'Reports')
  await expect(reports).toHaveClass(/is-open/)
  await expect(reports).toHaveClass(/is-active/)
  await expect(reports.locator(':scope > button')).toHaveAttribute('aria-expanded', 'true')
  await expect(page.locator('.app-sidebar [aria-current="page"]')).toHaveText('Stockist Reports')
  await expect(subgroup(page, 'CSV Import')).not.toHaveClass(/is-open/)
})

test('submenus expand and collapse independently, with mouse and keyboard', async ({ page }) => {
  const salesToggle = group(page, 'Sales Management').locator(':scope > button')
  await salesToggle.click()
  await expect(salesToggle).toHaveAttribute('aria-expanded', 'true')

  const reportsToggle = subgroup(page, 'Reports').locator(':scope > button')
  await expect(reportsToggle).toHaveAttribute('aria-expanded', 'false')
  await reportsToggle.focus()
  await page.keyboard.press('Enter')
  await expect(reportsToggle).toHaveAttribute('aria-expanded', 'true')
  await expect(subgroup(page, 'Reports').locator('.sidebar-link').first()).toBeVisible()
  // Closed subgroup's chevron must not rotate just because its parent is open.
  const chevronTransform = (toggle) => toggle.locator('.sidebar-group__chevron').evaluate((el) => getComputedStyle(el).transform)
  expect(await chevronTransform(subgroup(page, 'CSV Import').locator(':scope > button'))).toBe('none')

  await page.keyboard.press('Tab')
  await expect(page.locator(':focus')).toHaveText('Headquarters Reports')
  await page.keyboard.press('Enter')
  await expect(page).toHaveURL('/reports/headquarters')

  await reportsToggle.click()
  await expect(reportsToggle).toHaveAttribute('aria-expanded', 'false')
  await expect(subgroup(page, 'Reports').locator('.sidebar-link').first()).toBeHidden()
  await salesToggle.click()
  await expect(salesToggle).toHaveAttribute('aria-expanded', 'false')
})

test('Back/Forward update the active item across top-level links and nested pages', async ({ page }) => {
  const active = page.locator('.app-sidebar [aria-current="page"]')
  await page.locator('.app-sidebar a[href="/announcements"]').click()
  await expect(active).toHaveText('Announcements')
  await expect(page.locator('h1.page-header__title')).toHaveText('Announcements')
  await page.locator('.app-sidebar a[href="/reports/monthly-sales"]').evaluate((a) => a.click())
  await expect(active).toHaveText('Monthly Sales Reports')

  await page.goBack()
  await expect(page).toHaveURL('/announcements')
  await expect(active).toHaveText('Announcements')
  await page.goBack()
  await expect(page).toHaveURL('/dashboard')
  await expect(active).toHaveText('Dashboard')
  await expect(page.locator('.breadcrumb-item')).toHaveText(['Dashboard'])
  await page.goForward()
  await page.goForward()
  await expect(active).toHaveText('Monthly Sales Reports')
})

test('group URLs redirect to their first page; removed Headquarters Dashboard is a 404', async ({ page }) => {
  for (const [from, to] of [['/sales', '/sales/stockist-sales'], ['/reports', '/reports/headquarters'], ['/imports', '/imports/data'], ['/sales-app', '/sales-app/overview'], ['/products', '/products/group-categories']]) {
    await page.goto(from)
    await expect(page, from).toHaveURL(to)
  }
  await page.goto('/dashboard/headquarters')
  await expect(page.locator('h1.page-header__title')).toHaveText('Page not found')
})

test('collapsed desktop rail shows the 7 top-level icons; top-level links work there', async ({ page }) => {
  await page.getByRole('button', { name: 'Collapse sidebar' }).click()
  await expect(page.locator('.app-shell')).toHaveClass(/is-sidebar-collapsed/)
  await expect(page.locator('.sidebar-nav > li')).toHaveCount(7)
  await expect(page.locator('.sidebar-group__panel').first()).toBeHidden()
  await expect(page.locator('.sidebar-toplink.active')).toHaveAttribute('title', 'Dashboard')

  await page.locator('.sidebar-toplink', { hasText: 'Announcements' }).click()
  await expect(page).toHaveURL('/announcements')
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

  await group(page, 'Sales Management').locator(':scope > button').click()
  await subgroup(page, 'Reports').locator(':scope > button').click()
  await subgroup(page, 'Reports').getByRole('link', { name: 'Product Performance Reports' }).click()
  await expect(page).toHaveURL('/reports/product-performance')
  await expect(page.locator('.app-shell')).not.toHaveClass(/is-sidebar-open/)

  for (const width of [320, 375, 768]) {
    await page.setViewportSize({ width, height: 800 })
    await page.getByRole('button', { name: 'Open navigation menu' }).click()
    await group(page, 'Sales Management').locator(':scope > button').evaluate((b) => b.getAttribute('aria-expanded') === 'false' && b.click())
    await subgroup(page, 'Reports').locator(':scope > button').evaluate((b) => b.getAttribute('aria-expanded') === 'false' && b.click())
    await subgroup(page, 'CSV Import').locator(':scope > button').evaluate((b) => b.getAttribute('aria-expanded') === 'false' && b.click())
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
  await page.goto('/reports/product-performance')
  await subgroup(page, 'CSV Import').locator(':scope > button').click()
  await page.waitForTimeout(300)
  const clipped = await page.evaluate(() =>
    [...document.querySelectorAll('.sidebar-link, .sidebar-group__label, .sidebar-subgroup__label')]
      .filter((el) => el.offsetParent && el.scrollWidth > el.clientWidth + 1)
      .map((el) => el.textContent),
  )
  expect(clipped).toEqual([])
  await page.screenshot({ path: 'test-results/screens/sidebar-desktop-1440.png' })
})
