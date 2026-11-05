import { readFileSync } from 'node:fs'
import { USERS, expect, reseed, signIn, test } from './helpers.js'

const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'

test.beforeAll(() => reseed())

test('the browser rejects a non-Excel file before uploading', async ({ page }) => {
  await signIn(page, USERS.admin)
  await expect(page).toHaveURL('/dashboard')
  await page.goto('/imports/data')
  await page.getByLabel('Excel file').setInputFiles({ name: 'data.csv', mimeType: 'text/csv', buffer: Buffer.from('x') })
  await page.getByRole('button', { name: 'Upload and preview' }).click()
  await expect(page.getByText('Only .xlsx files can be imported (not .xls, .xlsm or .csv).')).toBeVisible()
  await expect(page.getByTestId('import-summary')).toHaveCount(0)
})

test('admin imports the Excel template (all data types in one sheet) and rolls it back', async ({ page }) => {
  await signIn(page, USERS.admin)
  await expect(page).toHaveURL('/dashboard')
  await page.goto('/imports/data')
  await expect(page.getByRole('list', { name: 'Import steps' })).toContainText('Upload file')
  await expect(page.getByTestId('import-dropzone')).toBeVisible()
  await expect(page.getByRole('table', { name: 'Columns for each data type' })).toContainText('Stockist')

  const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Download template' }).click()])
  expect(download.suggestedFilename()).toBe('yorilife-import-template.xlsx')
  const template = await download.path()

  await page.getByLabel('Excel file').setInputFiles({ name: 'yorilife-import-template.xlsx', mimeType: XLSX, buffer: readFileSync(template) })
  await page.getByRole('button', { name: 'Upload and preview' }).click()
  await expect(page.getByTestId('summary-total')).toHaveText(/7$/)
  await expect(page.getByTestId('summary-failed')).toHaveText(/Failed\s*0$/)
  const preview = page.getByRole('table', { name: 'Import preview' })
  await expect(preview.getByRole('columnheader', { name: 'Data Type' })).toBeVisible()
  await expect(preview.locator('tbody tr')).toHaveCount(7)
  await page.locator('#import-type-filter').selectOption('stockist')
  await expect(preview.locator('tbody tr')).toHaveCount(1)
  await page.locator('#import-type-filter').selectOption('')
  await page.getByLabel('Search preview').fill('zzz-no-match')
  await expect(preview).toContainText('No rows match these filters.')
  await page.getByLabel('Search preview').fill('')

  await page.getByRole('button', { name: 'Confirm import' }).click()
  await expect(page.getByRole('dialog')).toContainText('data types')
  await page.getByRole('dialog').getByRole('button', { name: 'Import' }).click()
  await expect(page.getByRole('heading', { name: 'Import complete' })).toBeVisible()
  await expect(page.getByRole('status').filter({ hasText: 'Import complete' })).toContainText(/\d+ created, \d+ updated/)
  await page.getByRole('link', { name: 'View in Import History' }).click()
  await expect(page).toHaveURL('/imports/history')

  const row = page.getByRole('row', { name: /yorilife-import-template\.xlsx/ }).first()
  await expect(row).toContainText('All data (Excel)')
  await expect(row).toContainText('Completed')
  await row.getByRole('button', { name: /Rollback/ }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByRole('button', { name: 'Roll back' }).click()
  await expect(dialog).toContainText('Rolled back')
  await dialog.locator('.modal-footer').getByRole('button', { name: 'Close' }).click()
  await expect(row).toContainText('Rolled back')
  await expect(row.getByRole('button', { name: /Rollback/ })).toHaveCount(0)
  const history = page.getByRole('button', { name: /Rollback history \(1\)/ }).first()
  await expect(history).toBeVisible()
  await expect(page.getByTestId('rollback-entry').first()).toContainText(/Rollback 1 .* restored, \d+ deleted, \d+ skipped/)
})

test('sales users do not see Data Import', async ({ page }) => {
  await signIn(page, USERS.sales)
  await expect(page).toHaveURL('/dashboard')
  await expect(page.locator('.app-sidebar').getByText('Data Import')).toHaveCount(0)
})