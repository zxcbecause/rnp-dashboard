import { expect, test } from '@playwright/test'
import { USERS, login } from './helpers'

const EDIT = 'Нажмите, чтобы ввести значение'
// Manual weekly CS metrics the fixtures leave empty: the head gets a "facts missing" alert for last week.
const MARIA = ['Исходящие звонки по базе (Мария)', 'Заинтересованные клиенты по базе (Мария)']

test.describe('Колокольчик', () => {
  test('алерт «не внесены факты» ведёт на неделю и исчезает, когда факты внесены', async ({ page }) => {
    await login(page, USERS.gleb)

    await page.getByRole('button', { name: /^Уведомления/ }).click()
    const alert = page.getByRole('link').filter({ hasText: /^Не внесены факты недели/ })
    await expect(alert).toContainText(MARIA[0])
    await alert.click()
    await expect(page).toHaveURL(/\/rnp-week\?.*week=/)

    for (const name of MARIA) {
      const row = page.locator('tbody tr').filter({ has: page.getByRole('link', { name, exact: true }) })
      await row.getByTitle(EDIT).nth(1).click()
      await row.locator('input').fill('12')
      await row.locator('input').press('Enter')
      await expect(row.locator('input')).toHaveCount(0)
      await expect(row).toContainText('12')
    }

    // Other alerts (red metrics, run rate) stay; this one is gone.
    await page.reload()
    await page.getByRole('button', { name: /^Уведомления/ }).click()
    await expect(page.getByText(/^Прогноз месяца ниже плана/)).toBeVisible()
    await expect(page.getByText(/^Не внесены факты недели/)).toHaveCount(0)
  })
})
