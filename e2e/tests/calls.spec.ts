import { expect, test } from '@playwright/test'
import type { APIRequestContext } from '@playwright/test'
import { PASSWORD, USERS, login, unitIds } from './helpers'

/** Day of the two missed calls of e2e/seed.sql (seeded as CURDATE() - 2 days; the stand runs in UTC). */
const callDay = new Date(Date.now() - 2 * 86_400_000).toISOString().slice(0, 10)

/** A 1×1 PNG: the screenshot a manager attaches to the request. */
const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='

/** Week fact of a metric in the RNP of a unit, read through the API as the given user. */
async function weekFact(request: APIRequestContext, email: string, unitId: number, code: string): Promise<string | null> {
  const auth = await request.post('/api/login', { data: { username: email, password: PASSWORD } })
  expect(auth.ok()).toBeTruthy()
  const { token } = await auth.json() as { token: string }
  const response = await request.get(`/api/org-units/${unitId}/rnp?week=${callDay}&showHidden=true`, { headers: { Authorization: `Bearer ${token}` } })
  expect(response.ok()).toBeTruthy()
  const rnp = await response.json() as { rows: { code: string, week: { fact: string | null } | null }[] }

  return rnp.rows.find(row => row.code === code)?.week?.fact ?? null
}

test.describe('Пропущенные звонки', () => {
  test('исключение звонка: заявка сотрудника → колокольчик → подтверждение → метрики пересчитаны; руководитель исключает сразу', async ({ page, request }) => {
    const units = await unitIds(request)
    const cs = units['Customer Service']

    // A manager of the unit asks: the request waits for the head.
    await login(page, USERS.csMember)
    await page.getByRole('link', { name: 'Пропущенные звонки' }).click()
    await expect(page).toHaveURL(/\/calls/)
    const calls = page.locator('li').filter({ has: page.getByText('ответственный #14 в Bitrix') })
    await expect(calls).toHaveCount(2)

    // The send button waits for a 10+ character reason.
    await calls.first().getByText('Исключить').click()
    let dialog = page.getByRole('dialog')
    const send = dialog.getByRole('button', { name: 'Отправить на подтверждение' })
    await dialog.locator('textarea').fill('связь')
    await expect(send).toBeDisabled()
    await dialog.locator('textarea').fill('Пропал интернет в офисе с 10:00 до 10:40')
    // A screenshot goes with the request.
    await dialog.locator('input[type=file]').setInputFiles({ name: 'screen.png', mimeType: 'image/png', buffer: Buffer.from(PNG, 'base64') })
    await expect(dialog.getByText('screen.png')).toBeVisible()
    await send.click()
    await expect(dialog).toBeHidden()
    await expect(page.getByText('На модерации: 1')).toBeVisible()
    await expect(page.getByText('на модерации', { exact: true })).toBeVisible()

    await page.getByRole('button', { name: /меню пользователя/i }).click()
    await page.getByRole('menuitem', { name: 'Выйти' }).click()
    await expect(page).toHaveURL(/\/login/)

    // The head is told in the bell.
    await login(page, USERS.gleb)
    await page.getByRole('button', { name: /^Уведомления/ }).click()
    await expect(page.getByText('Звонков на исключение ждут решения: 1')).toBeVisible()
    await page.keyboard.press('Escape')

    // Decision: approved → the call leaves the missed and the incoming calls.
    await page.getByRole('link', { name: 'Пропущенные звонки' }).click()
    await page.getByText('Решить').click()
    await expect(page.getByRole('dialog').getByRole('img', { name: 'Скриншот к заявке' })).toBeVisible()
    await page.getByRole('dialog').getByRole('button', { name: 'Подтвердить' }).click()
    await expect(page.getByRole('dialog')).toBeHidden()
    await expect(page.getByText(/^На модерации/)).toHaveCount(0)
    await expect(page.getByText('исключён', { exact: true })).toHaveCount(1)

    // Both calls were missed; one is excluded from both metrics.
    expect(Number(await weekFact(request, USERS.gleb, cs, 'cs_calls_missed'))).toBe(1)
    expect(Number(await weekFact(request, USERS.gleb, cs, 'cs_calls_total'))).toBe(1)

    // The head's own request needs no second step: a spam call is out at once.
    await calls.getByText('Исключить', { exact: true }).click()
    dialog = page.getByRole('dialog')
    await dialog.getByText('Спам', { exact: true }).click()
    await dialog.locator('textarea').fill('Рекламный обзвон, не клиент')
    await dialog.getByRole('button', { name: 'Исключить из статистики' }).click()
    await expect(dialog).toBeHidden()
    await expect(page.getByText('исключён', { exact: true })).toHaveCount(2)
    await expect(page.getByText(/^На модерации/)).toHaveCount(0)

    expect(Number(await weekFact(request, USERS.gleb, cs, 'cs_calls_missed'))).toBe(0)
    expect(Number(await weekFact(request, USERS.gleb, cs, 'cs_calls_total'))).toBe(0)
  })

  test('без отдела со звонками раздела нет', async ({ page }) => {
    await login(page, USERS.marketingViewer)
    await expect(page.getByRole('link', { name: 'Пропущенные звонки' })).toHaveCount(0)

    await page.goto('/calls')
    await expect(page.getByText('Нет отделов, где считаются пропущенные звонки.')).toBeVisible()
  })
})
