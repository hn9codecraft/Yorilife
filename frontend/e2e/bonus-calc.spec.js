import { api, expect, reseed, signIn, test, USERS } from './helpers.js'

test.beforeEach(() => reseed())

test('add employees, open a month, enter quantities and see the totals', async ({ page }) => {
  await signIn(page, USERS.admin)
  await expect(page).toHaveURL('/dashboard')

  const employee = await api(page, 'POST', '/api/sales-employees', {
    firstName: 'Asha',
    lastName: 'Rao',
    employeeCode: 'EMP-1',
  })
  await api(page, 'POST', '/api/products', {
    name: 'Yori Cap',
    packagingUnit: '10 x 10',
    mrp: '120.00',
    ptr: '96.00',
    pts: '86.00',
    scheme: '10+1',
    netRate: '80.00',
  })
  await api(page, 'POST', '/api/stockists', {
    name: 'Ambica Medical',
    code: 'STK-1',
    assignedEmployeeId: employee.employee.id,
  })

  await page.goto('/sales/bonus-calc')
  await expect(page.locator('h1.page-header__title')).toHaveText('Bonus Calc')
  await expect(page.getByRole('heading', { name: /Financial Year 2026-27/ })).toBeVisible()

  await page.getByRole('button', { name: 'Add Employee' }).first().click()
  const modal = page.locator('.modal.show')
  await modal.getByRole('checkbox', { name: 'Asha Rao (EMP-1)' }).check()
  await modal.getByRole('button', { name: 'Add' }).click()
  await expect(page.locator('.modal.show')).toHaveCount(0)

  const table = page.getByRole('table', { name: 'Financial Year 2026-27' })
  await expect(table.getByText('Asha Rao')).toBeVisible()
  await expect(table.getByText('EMP-1')).toBeVisible()
  await table.getByRole('button', { name: 'Open April for Asha Rao, no figures yet' }).click()

  await expect(page).toHaveURL(/\/sales\/bonus-calc\/2026-27\/\d+\/4$/)
  await expect(page.locator('h1.page-header__title')).toHaveText('Asha Rao')
  const crumbs = page.getByRole('navigation', { name: 'Breadcrumb' })
  await expect(crumbs.getByRole('link', { name: 'Dashboard' })).toHaveAttribute('href', '/dashboard')
  await expect(crumbs.getByRole('link', { name: 'Bonus Calc' })).toHaveAttribute('href', '/sales/bonus-calc')
  await expect(crumbs).toContainText('Sales Management')
  await expect(crumbs).toContainText('Asha Rao - April')
  await expect(page.getByRole('heading', { name: 'Grand total' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Ambica Medical', exact: true })).toBeVisible()
  await expect(page.getByText('1 product', { exact: true })).toBeVisible()
  await expect(page.getByRole('table', { name: 'Ambica Medical products' })).toBeVisible()
  await expect(page.getByRole('cell', { name: '10 x 10' })).toBeVisible()
  await expect(page.getByRole('cell', { name: '₹ 80.00' })).toBeVisible()

  await page.getByLabel('OP for Yori Cap at Ambica Medical').fill('10')
  await page.getByLabel('New Purchase for Yori Cap at Ambica Medical').fill('5.50')
  await page.getByLabel('Product Sales for Yori Cap at Ambica Medical').fill('4')
  await expect(page.getByRole('cell', { name: '15.50' }).first()).toBeVisible()
  await expect(page.getByRole('cell', { name: '₹ 320.00' }).first()).toBeVisible()

  await page.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Saved.' })).toBeVisible()

  await page.getByRole('link', { name: 'Back' }).click()
  await expect(page).toHaveURL(/\/sales\/bonus-calc\?year=2026-27$/)
  const row = table.locator('tbody tr').first()
  await expect(row.getByRole('button', { name: 'Open April for Asha Rao' })).toContainText('320.00')
  await expect(row).toContainText('320.00')
})

test('selected employees are removed from the year together', async ({ page }) => {
  await signIn(page, USERS.admin)
  await expect(page).toHaveURL('/dashboard')
  const asha = await api(page, 'POST', '/api/sales-employees', { firstName: 'Asha', lastName: 'Rao', employeeCode: 'EMP-1' })
  const bina = await api(page, 'POST', '/api/sales-employees', { firstName: 'Bina', lastName: 'Shah', employeeCode: 'EMP-2' })
  await api(page, 'POST', '/api/bonus-calc/rows', {
    financialYear: '2026-27',
    employeeIds: [asha.employee.id, bina.employee.id],
  })
  await page.goto('/sales/bonus-calc')
  const table = page.getByRole('table', { name: 'Financial Year 2026-27' })
  await expect(table.getByRole('checkbox', { name: 'Select all' })).toBeVisible()
  await table.getByRole('checkbox', { name: 'Select all' }).check()
  await page.getByRole('button', { name: 'Delete selected' }).click()
  await page.locator('.modal.show').getByRole('button', { name: 'Delete selected' }).click()
  await expect(page.getByText('No employees in Financial Year 2026-27')).toBeVisible()
  await expect(table.getByText('Asha Rao')).toHaveCount(0)
  await expect(table.getByText('Bina Shah')).toHaveCount(0)
})
