import { USERS, expect, reseed, signIn, test } from './helpers.js'

test.beforeAll(() => reseed())

test('admin adds an employee, writes a draft, publishes it and recalls it', async ({ page }) => {
  await signIn(page, USERS.admin)
  await expect(page).toHaveURL('/dashboard')

  // Employee list
  await page.goto('/announcements/employees')
  await page.getByRole('button', { name: 'Add employee' }).click()
  const employeeDialog = page.getByRole('dialog')
  await employeeDialog.getByLabel('Name').fill('Riya Shah')
  await employeeDialog.getByLabel('Email').fill('riya@example.test')
  await employeeDialog.getByLabel('Role').fill('Field Officer')
  await employeeDialog.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByRole('row', { name: /Riya Shah/ })).toContainText('Field Officer')

  // Draft
  await page.goto('/announcements/new')
  await expect(page.locator('h1.page-header__title')).toHaveText('New Announcement')
  await page.getByRole('button', { name: 'Save draft' }).click()
  await expect(page.getByText('Enter the title.')).toBeVisible()
  await page.getByLabel('Title').fill('Diwali holiday')
  await page.getByLabel('Priority').selectOption('urgent')
  await page.getByRole('textbox', { name: 'Message' }).click()
  await page.keyboard.type('Office closed on Friday.')
  await expect(page.getByTestId('audience-count')).toContainText(/\d+ (person|people) will receive it/)
  await page.getByRole('button', { name: 'Preview' }).click()
  await expect(page.getByRole('region', { name: 'Preview' })).toContainText('Diwali holiday')
  await page.getByRole('button', { name: 'Save draft' }).click()

  await expect(page).toHaveURL(/\/announcements\/\d+$/)
  await expect(page.getByRole('status').filter({ hasText: 'Draft saved' })).toBeVisible()
  await expect(page.locator('.breadcrumb-item')).toHaveText(['Dashboard', 'Announcements', 'All Announcements', 'Announcement'])

  // Publish
  await page.getByRole('button', { name: 'Publish', exact: true }).click()
  const publish = page.getByRole('dialog')
  await expect(publish.getByTestId('publish-summary')).toContainText(/will go to \d+ people/)
  await publish.getByRole('button', { name: 'Publish now' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Published.' })).toBeVisible()
  await expect(page.getByTestId('tracking-stats')).toContainText('Sent to')
  await expect(page.getByText('App not connected yet.')).toBeVisible()
  await expect(page.getByTestId('announcement-history')).toContainText('Published')

  // Recall
  await page.getByRole('button', { name: 'Recall' }).click()
  const recall = page.getByRole('dialog')
  await recall.getByRole('button', { name: 'Recall' }).click()
  await expect(recall).toContainText('Enter the reason for the recall.')
  await recall.getByLabel('Reason').fill('Sent by mistake')
  await recall.getByRole('button', { name: 'Recall' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Reason: Sent by mistake' })).toBeVisible()

  await page.goto('/announcements/list')
  const row = page.getByRole('row', { name: /Diwali holiday/ })
  await expect(row).toContainText('Recalled')
  await expect(row).toContainText('Urgent')
})

test('sales users do not see Announcements', async ({ page }) => {
  await signIn(page, USERS.sales)
  await expect(page).toHaveURL('/dashboard')
  await expect(page.locator('.app-sidebar').getByText('Announcements')).toHaveCount(0)
  await page.goto('/announcements/list')
  await expect(page.getByText('You don\u2019t have access to this page')).toBeVisible()
})