import { expect } from '@playwright/test'
import type { APIRequestContext, Page } from '@playwright/test'

/** Password of every fixture account (backend AppFixtures::PASSWORD). */
export const PASSWORD = 'rnp-local-123'

export const USERS = {
  admin: 'admin@rnp.test',
  oleg: 'oleg@rnp.test',
  gleb: 'gleb@rnp.test',
  marketingViewer: 'marketing.viewer@rnp.test',
  /** member of Customer Service, added by e2e/seed.sql */
  csMember: 'cs.member@rnp.test',
} as const

/** Signs in through the login form and waits for the app shell. */
export async function login(page: Page, email: string, password = PASSWORD): Promise<void> {
  await page.goto('/login')
  await page.locator('#email').fill(email)
  await page.locator('#password').fill(password)
  await page.getByRole('button', { name: 'Войти' }).click()
  await expect(page.getByRole('button', { name: /меню пользователя/i })).toBeVisible()
}

/**
 * Org unit ids by name, read through the API as the admin (ids depend on fixture insert order, names do not).
 * Uses its own request context: the page session is not affected.
 */
export async function unitIds(request: APIRequestContext): Promise<Record<string, number>> {
  const auth = await request.post('/api/login', { data: { username: USERS.admin, password: PASSWORD } })
  expect(auth.ok()).toBeTruthy()
  const { token } = await auth.json() as { token: string }

  const response = await request.get('/api/org-units', { headers: { Authorization: `Bearer ${token}` } })
  expect(response.ok()).toBeTruthy()
  const units = await response.json() as { id: number, name: string }[]

  return Object.fromEntries(units.map(unit => [unit.name, unit.id]))
}
