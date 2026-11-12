import { expect, test } from '@playwright/test'
import type { APIRequestContext, Page } from '@playwright/test'
import { PASSWORD, USERS, login, unitIds } from './helpers'

const EDIT = 'Нажмите, чтобы ввести значение'
const DIRECT = 'Прямые бронирования по базе — сумма'
const WEEK = '2026-11-02'

interface Row { code: string, planFormula: string | null, month: { plan: string | null, fact: string | null } }

/** RNP rows of a unit for the week, read through the API as Gleb (head of CS). */
async function rows(request: APIRequestContext, unitId: number): Promise<Record<string, Row>> {
  const auth = await request.post('/api/login', { data: { username: USERS.gleb, password: PASSWORD } })
  const { token } = await auth.json() as { token: string }
  const response = await request.get(`/api/org-units/${unitId}/rnp?week=${WEEK}&showHidden=true`, { headers: { Authorization: `Bearer ${token}` } })
  expect(response.ok()).toBeTruthy()
  const rnp = await response.json() as { rows: Row[] }
  return Object.fromEntries(rnp.rows.map(row => [row.code, row]))
}

function directRow(page: Page) {
  return page.locator('tbody tr').filter({ has: page.getByRole('link', { name: DIRECT, exact: true }) })
}

test.describe('План по формуле', () => {
  test('план прямых по базе = 30 % входящих; ручной план перекрывает формулу, очистка возвращает', async ({ page, request }) => {
    const cs = (await unitIds(request))['Customer Service']
    const before = await rows(request, cs)
    const direct = before.cs_direct_base_sum
    expect(direct.planFormula).toBe('cs_direct_base_share.plan * cs_incoming_sum')
    // The month plan follows the month's incoming bookings to date.
    expect(Number(direct.month.plan)).toBeCloseTo(0.3 * Number(before.cs_incoming_sum.month.fact), 2)

    await login(page, USERS.gleb)
    await page.goto(`/rnp-week?unit=${cs}&week=${WEEK}`)
    const row = directRow(page)
    await expect(row.getByText('план по формуле')).toBeVisible()

    // Computed metric: only plans are editable — week plan (0) and month plan (1).
    await row.getByTitle(EDIT).nth(1).click()
    await row.locator('input').fill('5000')
    await row.locator('input').press('Enter')
    await expect(row.locator('input')).toHaveCount(0)
    await expect(row.getByText('план по формуле')).toHaveCount(0)
    expect((await rows(request, cs)).cs_direct_base_sum.month.plan).toBe('5000')

    // Clearing the set plan brings the formula back.
    await row.getByTitle(EDIT).nth(1).click()
    await row.locator('input').fill('')
    await row.locator('input').press('Enter')
    await expect(row.getByText('план по формуле')).toBeVisible()
    expect((await rows(request, cs)).cs_direct_base_sum.month.plan).toBe(direct.month.plan)
  })
})
