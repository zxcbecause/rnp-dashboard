import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import { USERS, login, unitIds } from './helpers'

const EDIT = 'Нажмите, чтобы ввести значение'
const AD_SPEND = 'Расход на рекламу'

/** Row of a metric in the RNP table of the shown unit. */
function metricRow(page: Page, name: string) {
  return page.locator('tbody tr').filter({ has: page.getByRole('link', { name, exact: true }) })
}

/** Clicks an editable cell of the row (0 = week plan, 1 = week fact, 2 = month plan), types and submits. */
async function enter(page: Page, name: string, cell: number, value: string) {
  const row = metricRow(page, name)
  await row.getByTitle(EDIT).nth(cell).click()
  const input = row.locator('input')
  await input.fill(value)
  await input.press('Enter')
}

test.describe('РНП-неделя', () => {
  test('руководитель вносит план и факт — считается % выполнения и сохраняется', async ({ page }) => {
    await login(page, USERS.oleg)
    await page.goto('/rnp-week')
    const row = metricRow(page, AD_SPEND)
    await expect(row).toBeVisible()

    // Ad spend is "lower is better": plan 2000 / fact 1000 = 200 % (the cap).
    await enter(page, AD_SPEND, 0, '2000')
    await expect(row.locator('input')).toHaveCount(0)
    await enter(page, AD_SPEND, 1, '1000')
    await expect(row.locator('input')).toHaveCount(0)

    await expect(row).toContainText(/2[\s\u00a0\u202f]?000/)
    await expect(row).toContainText(/1[\s\u00a0\u202f]?000/)
    // Completion pill of the week: exactly "200 %", not a digit sequence somewhere in the row.
    await expect(row.getByText(/^200(,0)?[\s\u00a0\u202f]?%$/).first()).toBeVisible()

    await page.reload()
    await expect(metricRow(page, AD_SPEND)).toContainText(/1[\s\u00a0\u202f]?000/)
  })

  test('неверное значение не сохраняется — показывается ошибка', async ({ page }) => {
    await login(page, USERS.oleg)
    await page.goto('/rnp-week')
    const row = metricRow(page, AD_SPEND)

    await row.getByTitle(EDIT).nth(1).click()
    await row.locator('input').fill('много')
    await row.locator('input').press('Enter')

    // The editor stays open with the reason; Esc closes it without saving.
    await expect(row.locator('small')).toBeVisible()
    await row.locator('input').press('Escape')
    await expect(row.locator('input')).toHaveCount(0)
  })

  test('страница метрики: график и «Логика показателя»', async ({ page }) => {
    await login(page, USERS.oleg)
    await page.goto('/rnp-week')
    await page.getByRole('link', { name: AD_SPEND, exact: true }).click()

    await expect(page).toHaveURL(/\/metrics\/\d+/)
    await expect(page.getByRole('heading', { name: AD_SPEND, exact: true })).toBeVisible()
    await expect(page.locator('canvas')).toBeVisible()
    await expect(page.getByText('Логика показателя')).toBeVisible()
    await expect(page.getByText('Действия по метрике')).toBeVisible()
  })

  test('наблюдатель только читает свой отдел, чужой отдел недоступен', async ({ page, request }) => {
    const units = await unitIds(request)
    await login(page, USERS.marketingViewer)

    await page.goto('/rnp-week')
    await expect(metricRow(page, AD_SPEND)).toBeVisible()
    await expect(page.getByTitle(EDIT)).toHaveCount(0)

    await page.goto(`/rnp-week?unit=${units.Revenue}`)
    await expect(page.getByText('Отдел не найден или недоступен')).toBeVisible()
  })
})
