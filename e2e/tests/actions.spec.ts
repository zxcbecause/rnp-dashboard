import { expect, test } from '@playwright/test'
import { USERS, login } from './helpers'

test.describe('Действия, журнал ошибок, спринты', () => {
  test('отметка базовой функции: выполнено → не выполнено', async ({ page }) => {
    await login(page, USERS.oleg)
    await page.goto('/actions')

    const fn = page.locator('li').filter({ hasText: 'Запускать 2 новых креатива в неделю' })
    // The slot of the shown (current) week: its title starts with the state.
    const slot = fn.locator('button[title]').first()
    await expect(slot).toBeVisible()

    await slot.click()
    await expect(slot).toHaveAttribute('title', /^Выполнено/)
    await slot.click()
    await expect(slot).toHaveAttribute('title', /^Не выполнено/)
  })

  test('запись в журнал ошибок появляется в списке', async ({ page }) => {
    const description = `E2E: рекламный кабинет заблокирован на сутки ${Date.now()}`
    await login(page, USERS.oleg)
    await page.goto('/errors')

    await page.getByRole('button', { name: 'Записать ошибку' }).click()
    const dialog = page.getByRole('dialog')
    await dialog.getByLabel('Что произошло').fill(description)
    await dialog.locator('label', { hasText: 'Последствия' }).locator('.p-select').click()
    await page.getByRole('option', { name: 'Блокировка' }).click()
    await dialog.getByRole('button', { name: 'Сохранить' }).click()

    await expect(dialog).toBeHidden()
    const entry = page.locator('li').filter({ hasText: description })
    await expect(entry).toBeVisible()
    await expect(entry).toContainText('Блокировка')
    await expect(entry).toContainText('Открыта')
  })

  test('задача спринта: создание и прогресс 100 %', async ({ page }) => {
    const title = `E2E: проверить креативы ${Date.now()}`
    await login(page, USERS.oleg)
    await page.goto('/sprints')

    await page.getByRole('button', { name: 'Добавить задачу' }).click()
    const dialog = page.getByRole('dialog')
    await dialog.getByLabel('Задача', { exact: true }).fill(title)
    await dialog.getByRole('button', { name: 'Сохранить' }).click()
    await expect(dialog).toBeHidden()

    const row = page.locator('tbody tr').filter({ hasText: title })
    await expect(row).toContainText('0%')
    await row.getByRole('button', { name: '100%' }).click()
    await expect(row.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '100')
    await expect(row.getByRole('button', { name: '100%' })).toBeDisabled()
  })
})
