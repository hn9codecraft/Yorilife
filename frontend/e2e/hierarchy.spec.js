import { api, expect, reseed, seedManagerChain, signIn, test, USERS } from './helpers.js'

test.beforeEach(() => {
  reseed()
})

test('the headquarter list state choices come from the State List', async ({ page }) => {
  await signIn(page, USERS.admin)
  await expect(page).toHaveURL('/dashboard')
  await page.goto('/sales-office/headquarter-list')
  await expect(page.locator('h1.page-header__title')).toHaveText('Headquarter List')
  await page.getByRole('button', { name: 'Add headquarter' }).click()
  const state = page.locator('.modal.show').getByLabel('State')
  await expect(state.locator('option')).toHaveCount(7)
  await expect(state.locator('option', { hasText: 'Gujarat' })).toHaveCount(1)
  await expect(state.locator('option', { hasText: 'Delhi' })).toHaveCount(0)
})

test('the hierarchy page shows the chain from a 3rd line manager down to stockists', async ({ page }) => {
  test.setTimeout(90_000)
  await signIn(page, USERS.admin)
  await expect(page).toHaveURL('/dashboard')
  const chain = await seedManagerChain(page, { third: 'Chain Third', second: 'Chain Second', first: 'Chain First' })

  await page.goto('/sales-office/headquarter-list')
  await page.getByRole('button', { name: 'Add headquarter' }).click()
  const locationModal = page.locator('.modal.show')
  await locationModal.getByLabel('State').selectOption('Gujarat')
  await locationModal.getByLabel('Headquarters name').fill('Chain HQ')
  await locationModal.getByLabel('Headquarters address').fill('1 Chain Road')
  await locationModal.getByLabel('Pincode').fill('380001')
  await locationModal.getByLabel('Phone number').fill('9000000201')
  await locationModal.getByLabel('Email').fill('chain-hq@example.test')
  await locationModal.getByRole('button', { name: 'Save' }).click()
  const locationTable = page.getByRole('table', { name: 'Headquarters' })
  await expect(locationTable.locator('tbody tr', { hasText: 'Chain HQ' })).toContainText('HQ-001')
  await page.reload()
  await expect(locationTable.locator('tbody tr', { hasText: 'Chain HQ' })).toContainText('chain-hq@example.test')

  const locations = (await api(page, 'GET', '/api/locations')).locations
  const source = locations.find((location) => location.name === 'Gujarat').headquarters.find((office) => office.name === 'Chain HQ')
  await api(page, 'POST', '/api/headquarters', {
    locationHeadquartersId: source.id,
    parentManagerId: chain.first.id,
  })

  await page.goto('/sales-office/stockists')
  await page.getByRole('button', { name: 'Add stockist' }).click()
  const stockist = page.locator('.modal.show')
  await stockist.getByLabel('Stockist name').fill('Chain Stockist')
  await expect(stockist.getByLabel('Stockist code')).toHaveValue('001')
  await stockist.getByLabel('Headquarters').selectOption({ label: 'Chain HQ (HQ-001)' })
  await stockist.getByLabel('Address').fill('1 Chain Road')
  await stockist.getByLabel('Pincode').fill('380001')
  await stockist.getByLabel('Phone number').fill('12452724')
  await stockist.getByLabel('Email').fill('chain-stockist@example.test')
  await stockist.getByRole('button', { name: 'Save' }).click()
  const stockistRow = page.locator('tbody tr', { hasText: 'Chain Stockist' })
  await expect(stockistRow).toHaveCount(1)
  await expect(page.getByRole('table', { name: 'Stockists' }).locator('thead th')).toHaveText(['', 'Stockist', 'Code', 'Headquarters', 'State', 'Address', 'Pincode', 'Phone Number', 'Email', 'Actions'])
  await expect(stockistRow).toContainText('ST-001')
  await expect(stockistRow).toContainText('Gujarat')
  await expect(stockistRow).toContainText('12452724')
  await page.getByRole('button', { name: 'Filter list' }).click()
  const stockistFilter = page.getByRole('dialog', { name: 'Filter list' })
  await expect(stockistFilter.getByText('State', { exact: true })).toBeVisible()
  await expect(stockistFilter.getByText('Reports To', { exact: true })).toHaveCount(0)
  await page.keyboard.press('Escape')

  await page.goto('/sales-office/hierarchy')
  await page.getByLabel('Search the sales office hierarchy').fill('Chain Stockist')
  const branch = page.locator('details.card').filter({ hasText: 'Chain Third' })
  await expect(branch.getByText('Chain Second', { exact: true })).toBeVisible()
  await expect(branch.getByText('Chain First', { exact: true })).toBeVisible()
  await expect(branch.getByText('Chain HQ', { exact: true })).toBeVisible()
  await expect(branch.getByRole('link', { name: 'Chain HQ' })).toHaveCount(0)
  await expect(branch.getByRole('link', { name: 'Chain Stockist' })).toBeVisible()

  await branch.locator('summary').first().click()
  await expect(branch.getByRole('link', { name: 'Chain Stockist' })).toBeHidden()

  await page.goto('/sales-office/stockists')
  await expect(page.getByRole('columnheader', { name: 'Actions' })).toBeVisible()
  await page.getByRole('button', { name: 'Delete Chain Stockist' }).click()
  await page.getByRole('dialog', { name: 'Delete stockist' }).getByRole('button', { name: 'Delete' }).click()
  await expect(page.locator('tbody tr', { hasText: 'Chain Stockist' })).toHaveCount(0)
})

test('deleting a headquarters office keeps the headquarter list row', async ({ page }) => {
  test.setTimeout(90_000)
  await signIn(page, USERS.admin)
  await expect(page).toHaveURL('/dashboard')
  const chain = await seedManagerChain(page, { third: 'Delete Third', second: 'Delete Second', first: 'Delete First' })

  await page.goto('/sales-office/headquarter-list')
  await page.getByRole('button', { name: 'Add headquarter' }).click()
  const locationModal = page.locator('.modal.show')
  await locationModal.getByLabel('State').selectOption('Gujarat')
  await locationModal.getByLabel('Headquarters name').fill('Delete HQ')
  await locationModal.getByLabel('Headquarters address').fill('1 Delete Road')
  await locationModal.getByLabel('Pincode').fill('380001')
  await locationModal.getByLabel('Phone number').fill('9000000301')
  await locationModal.getByLabel('Email').fill('delete-hq@example.test')
  await locationModal.getByRole('button', { name: 'Save' }).click()

  const locations = (await api(page, 'GET', '/api/locations')).locations
  const source = locations.find((location) => location.name === 'Gujarat').headquarters.find((office) => office.name === 'Delete HQ')
  const created = await api(page, 'POST', '/api/headquarters', {
    locationHeadquartersId: source.id,
    parentManagerId: chain.first.id,
  })

  await page.goto('/sales-office/stockists')
  await page.getByRole('button', { name: 'Add stockist' }).click()
  const stockist = page.locator('.modal.show')
  await stockist.getByLabel('Stockist name').fill('Delete Stockist')
  await stockist.getByLabel('Headquarters').selectOption({ label: 'Delete HQ (HQ-001)' })
  await stockist.getByRole('button', { name: 'Save' }).click()
  await expect(page.locator('tbody tr', { hasText: 'Delete Stockist' })).toHaveCount(1)

  await api(page, 'POST', `/api/headquarters/${created.headquarters.id}/delete`, {})
  await page.goto('/sales-office/stockists')
  await expect(page.locator('tbody tr', { hasText: 'Delete Stockist' })).toHaveCount(0)

  await page.goto('/sales-office/headquarter-list')
  const locationRow = page.getByRole('table', { name: 'Headquarters' }).locator('tbody tr', { hasText: 'Delete HQ' })
  await expect(locationRow).toContainText('HQ-001')
  await locationRow.getByRole('button', { name: 'Delete Delete HQ' }).click()
  await page.getByRole('dialog', { name: 'Delete headquarter' }).getByRole('button', { name: 'Delete' }).click()
  await expect(page.getByRole('table', { name: 'Headquarters' }).locator('tbody tr', { hasText: 'Delete HQ' })).toHaveCount(0)
})
