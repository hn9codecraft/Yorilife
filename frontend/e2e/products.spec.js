import { chooseOption, clearOption, expect, reseed, signIn, test, USERS } from './helpers.js'

test.beforeEach(() => reseed())

test('category page manages product types and product groups in popups', async ({ page }) => {
  await signIn(page, USERS.admin)
  await expect(page).toHaveURL('/dashboard')
  await page.goto('/products/category')
  await expect(page.locator('h1.page-header__title')).toHaveText('Category')
  const types = page.getByRole('table', { name: 'Product Type' })
  const groups = page.getByRole('table', { name: 'Product Group' })
  await expect(types.locator('thead th')).toHaveText(['Product Type', 'Products', 'Last Updated', 'Actions'])
  await expect(groups.locator('thead th')).toHaveText(['Product Group', 'Products', 'Last Updated', 'Actions'])
  await expect(groups.getByText('No product groups yet')).toBeVisible()
  await expect(groups.getByRole('button', { name: 'Add product group' })).toBeVisible()

  for (const name of ['Tablet', 'Syrup']) {
    await page.getByRole('button', { name: 'Add product type' }).first().click()
    const modal = page.locator('.modal.show')
    await modal.getByLabel('Product Type').fill(name)
    await modal.getByRole('button', { name: 'Save' }).click()
    await expect(page.locator('.modal.show')).toHaveCount(0)
  }
  // Names are unique, not case-sensitive.
  await page.getByRole('button', { name: 'Add product type' }).first().click()
  let modal = page.locator('.modal.show')
  await modal.getByLabel('Product Type').fill('tablet')
  await modal.getByRole('button', { name: 'Save' }).click()
  await expect(modal.getByText('This product type already exists.')).toBeVisible()
  await modal.getByRole('button', { name: 'Cancel' }).click()

  for (const name of ['Focus Product', 'Best Selling']) {
    await page.getByRole('button', { name: 'Add product group' }).first().click()
    modal = page.locator('.modal.show')
    await modal.getByLabel('Product Group').fill(name)
    await modal.getByRole('button', { name: 'Save' }).click()
    await expect(page.locator('.modal.show')).toHaveCount(0)
  }
  await expect(groups.locator('tbody tr')).toHaveCount(2)

  await page.getByRole('button', { name: 'Edit Syrup' }).click()
  modal = page.locator('.modal.show')
  await modal.getByLabel('Product Type').fill('Oral Syrup')
  await modal.getByRole('button', { name: 'Save' }).click()
  await expect(types.locator('tbody tr', { hasText: 'Oral Syrup' })).toHaveCount(1)

  await page.getByRole('button', { name: 'Delete Best Selling' }).click()
  await page.locator('.modal.show').getByRole('button', { name: 'Delete' }).click()
  await expect(groups.locator('tbody tr', { hasText: 'Best Selling' })).toHaveCount(0)

  await page.getByRole('search', { name: 'Filter product types' }).getByRole('searchbox').fill('oral')
  await expect(types.locator('tbody tr')).toHaveCount(1)
  await expect(page).toHaveURL(/qt=oral/)
})

test('product list adds a product with type and group, filters it and blocks deleting a used group', async ({ page }) => {
  await signIn(page, USERS.admin)
  await expect(page).toHaveURL('/dashboard')
  await page.goto('/products/category')
  for (const [button, label, name] of [['Add product type', 'Product Type', 'Capsule'], ['Add product group', 'Product Group', 'Popular']]) {
    await page.getByRole('button', { name: button }).first().click()
    const modal = page.locator('.modal.show')
    await modal.getByLabel(label).fill(name)
    await modal.getByRole('button', { name: 'Save' }).click()
    await expect(page.locator('.modal.show')).toHaveCount(0)
  }

  await page.goto('/products/list')
  await expect(page.locator('h1.page-header__title')).toHaveText('Product')
  const table = page.getByRole('table', { name: 'Products' })
  await expect(table.locator('thead th')).toHaveText(['', 'Product', 'Product Type', 'Packaging Unit', 'Product Group', 'MRP', 'PTR', 'PTS', 'Scheme', 'Net Rate', 'Actions'])

  await page.getByRole('button', { name: 'Add product', exact: true }).first().click()
  let modal = page.locator('.modal.show')
  await modal.getByRole('button', { name: 'Save' }).click()
  await expect(modal.getByText('Enter the product name.')).toBeVisible()
  await modal.getByLabel('Product Name').fill('Yori Cap')
  await modal.getByLabel('SKU').fill('YC-10')
  await chooseOption(modal, 'Product Type', 'Capsule')
  await modal.getByLabel('Packaging Unit').fill('10 x 10')
  await chooseOption(modal, 'Product Group', 'Popular')
  await modal.getByLabel('Scheme').fill('10+1')
  await modal.getByLabel('MRP').fill('-5')
  await modal.getByRole('button', { name: 'Save' }).click()
  await expect(modal.getByText('MRP cannot be negative.')).toBeVisible()
  await modal.getByLabel('MRP').fill('120.50')
  await modal.getByLabel('Net Rate').fill('80')
  await modal.getByRole('button', { name: 'Save' }).click()
  await expect(page.locator('.modal.show')).toHaveCount(0)

  await page.getByRole('button', { name: 'Add product', exact: true }).first().click()
  modal = page.locator('.modal.show')
  await modal.getByLabel('Product Name').fill('Name Only')
  await modal.getByRole('button', { name: 'Save' }).click()
  await expect(page.locator('.modal.show')).toHaveCount(0)

  const row = table.locator('tbody tr', { hasText: 'Yori Cap' })
  await expect(row).toContainText('SKU YC-10')
  await expect(row).toContainText('Capsule')
  await expect(row).toContainText('Popular')
  await expect(row).toContainText('₹ 120.50')
  await expect(row).toContainText('₹ 80.00')

  const filters = page.getByRole('search', { name: 'Filter products' })
  await chooseOption(filters, 'Product Group', 'Popular')
  await expect(table.locator('tbody tr')).toHaveCount(1)
  await expect(page).toHaveURL(/group=/)
  await clearOption(filters, 'Product Group')
  await chooseOption(filters, 'Product Type', 'No product type')
  await expect(table.locator('tbody tr')).toHaveCount(1)
  await expect(table.locator('tbody tr')).toContainText('Name Only')
  await clearOption(filters, 'Product Type')
  await filters.getByRole('searchbox').fill('yc-1')
  await expect(table.locator('tbody tr')).toHaveCount(1)
  await expect(filters.getByRole('button', { name: 'Export CSV' })).toBeEnabled()

  // A group used by a product cannot be deleted.
  await page.goto('/products/category')
  await expect(page.getByRole('button', { name: 'Delete Popular' })).toBeDisabled()
})