import { PASSWORD, USERS, expect, reseed, signIn, test } from './helpers.js'

test.beforeEach(() => reseed())

test('login page renders branding and an accessible form, with no demo credentials', async ({ page }) => {
  await page.goto('/login')

  await expect(page).toHaveTitle('Sign in · Yorilife ERP')
  await expect(page.getByRole('heading', { level: 1, name: 'Sign in to Yorilife ERP' })).toBeVisible()
  const logo = page.getByRole('img', { name: 'Yorilife Sciences' })
  await expect(logo).toBeVisible()
  const box = await logo.boundingBox()
  expect(box.width / box.height).toBeCloseTo(792 / 365, 2)

  const body = await page.locator('body').innerText()
  expect(body).not.toMatch(/example\.test|demo|admin@/i)

  const password = page.getByLabel('Password', { exact: true })
  await password.fill('secret-value')
  await expect(password).toHaveAttribute('type', 'password')
  await page.getByRole('button', { name: 'Show password' }).click()
  await expect(password).toHaveAttribute('type', 'text')
  await page.getByRole('button', { name: 'Hide password' }).click()
  await expect(password).toHaveAttribute('type', 'password')
})

test('client-side validation shows field errors and focuses the first one', async ({ page }) => {
  await page.goto('/login')
  await page.getByRole('button', { name: 'Sign in' }).click()

  await expect(page.getByText('Enter your email address.')).toBeVisible()
  await expect(page.getByText('Enter your password.')).toBeVisible()
  await expect(page.getByLabel('Email address')).toBeFocused()
  await expect(page.getByLabel('Email address')).toHaveAttribute('aria-invalid', 'true')

  await page.getByLabel('Email address').fill('not-an-email')
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page.getByText('Enter a valid email address.')).toBeVisible()
})

test('protected page redirects to login and returns there after signing in', async ({ page }) => {
  await page.goto('/products/list?x=1')
  await expect(page).toHaveURL('/login?next=%2Fproducts%2Flist%3Fx%3D1')

  await page.getByLabel('Email address').fill(USERS.sales)
  await page.getByLabel('Password', { exact: true }).fill(PASSWORD)
  await page.getByRole('button', { name: 'Sign in' }).click()

  await expect(page).toHaveURL('/products/list?x=1')
  await expect(page.getByRole('heading', { level: 1, name: 'Product List' })).toBeVisible()
})

test('external "next" targets are ignored (no open redirect)', async ({ page }) => {
  await signIn(page, USERS.sales, { path: '/login?next=https%3A%2F%2Fevil.example%2F' })
  await expect(page).toHaveURL('/dashboard')
})

test('wrong password and unknown email show the same generic error', async ({ page }) => {
  await signIn(page, USERS.admin, { password: 'definitely-wrong' })
  const alert = page.getByRole('alert')
  await expect(alert).toHaveText('The email or password is incorrect.')
  await expect(alert).toBeFocused()
  await expect(page.getByLabel('Password', { exact: true })).toHaveValue('')

  await signIn(page, 'nobody@example.test', { password: 'definitely-wrong' })
  await expect(page.getByRole('alert')).toHaveText('The email or password is incorrect.')
  await expect(page).toHaveURL(/\/login/)
})

test('disabled account is told to contact an administrator', async ({ page }) => {
  await signIn(page, USERS.disabled)
  await expect(page.getByRole('alert')).toHaveText('This account is disabled. Contact an administrator.')
})

test('repeated failures are throttled with a clear message', async ({ page }) => {
  await page.goto('/login')
  for (let i = 0; i < 5; i++) {
    await page.getByLabel('Email address').fill(USERS.sales)
    await page.getByLabel('Password', { exact: true }).fill(`wrong-${i}`)
    await page.getByRole('button', { name: 'Sign in' }).click()
    await expect(page.getByRole('alert')).toContainText('incorrect')
  }
  await page.getByLabel('Password', { exact: true }).fill(PASSWORD)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page.getByRole('alert')).toContainText('Too many failed sign-in attempts')
  await expect(page.getByRole('alert')).toContainText('minute')
})

test('session is restored from the server after a browser reload', async ({ page }) => {
  await signIn(page, USERS.admin)
  await expect(page).toHaveURL('/dashboard')

  await page.reload()
  await expect(page).toHaveURL('/dashboard')
  await expect(page.getByRole('button', { name: 'Account menu for E2E Admin' })).toBeVisible()
  // Nothing auth-related is persisted in web storage.
  expect(await page.evaluate(() => JSON.stringify({ ...localStorage, ...sessionStorage }))).not.toMatch(/csrf|session|token|e2e/i)
})

test('signed-in users are sent from /login to the dashboard', async ({ page }) => {
  await signIn(page, USERS.sales)
  await expect(page).toHaveURL('/dashboard')
  await page.goto('/login')
  await expect(page).toHaveURL('/dashboard')
})

test('sign out ends the server session and returns to login', async ({ page }) => {
  await signIn(page, USERS.admin)
  await page.getByRole('button', { name: 'Account menu for E2E Admin' }).click()
  await expect(page.getByText(USERS.admin)).toBeVisible()
  await page.getByRole('button', { name: 'Sign out' }).click()

  await expect(page).toHaveURL('/login')
  await expect(page.getByText('You have signed out.')).toBeVisible()
  expect(await page.evaluate(async () => (await fetch('/api/auth/me')).status)).toBe(401)

  // The old session can't be reused: protected pages bounce back to login.
  await page.goto('/dashboard')
  await expect(page).toHaveURL('/login')
  await page.goBack()
  await expect(page.locator('.app-sidebar')).toHaveCount(0)
})

test('an expired session sends the user to login with a notice, then back', async ({ page, context }) => {
  await signIn(page, USERS.admin)
  await expect(page).toHaveURL('/dashboard')

  await context.clearCookies() // as if the server session expired
  await page.locator('.app-sidebar a[href="/system/users"]').evaluate((a) => a.click())

  await expect(page).toHaveURL('/login?next=%2Fsystem%2Fusers')
  await expect(page.getByText('Your session has ended. Please sign in again to continue.')).toBeVisible()

  await page.getByLabel('Email address').fill(USERS.admin)
  await page.getByLabel('Password', { exact: true }).fill(PASSWORD)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL('/system/users')
})

test('server errors during the session check show a retry screen', async ({ page, consoleErrors }) => {
  await page.route('**/api/auth/me', (route) =>
    route.fulfill({ status: 503, contentType: 'application/json', body: '{"success":false,"error":{"code":"x","message":"Service temporarily unavailable."}}' }),
  )
  await page.goto('/dashboard')
  await expect(page.getByRole('heading', { name: 'We couldn’t check your session' })).toBeVisible()
  await expect(page.getByText('Service temporarily unavailable.')).toBeVisible()

  await page.unroute('**/api/auth/me')
  await page.getByRole('button', { name: 'Try again' }).click()
  // Retry succeeds (no session) → normal sign-in page.
  await expect(page).toHaveURL('/login')

  // The simulated 503 is logged by Chrome; that one line is expected here.
  const expected = consoleErrors.filter((e) => e.includes('status of 503'))
  expect(expected).toHaveLength(1)
  consoleErrors.splice(consoleErrors.indexOf(expected[0]), 1)
})
