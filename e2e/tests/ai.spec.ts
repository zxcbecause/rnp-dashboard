import { expect, test } from '@playwright/test'
import type { Page } from '@playwright/test'
import { USERS, login } from './helpers'

// The stand runs the backend in the test environment: the model is a scripted fake (FakeLlmClient),
// so these tests check the UI end to end without calling the real API.

const AD_SPEND = 'Расход на рекламу'

function metricRow(page: Page, name: string) {
  return page.locator('tbody tr').filter({ has: page.getByRole('link', { name, exact: true }) })
}

async function enter(page: Page, name: string, cell: number, value: string) {
  const row = metricRow(page, name)
  await row.getByTitle('Нажмите, чтобы ввести значение').nth(cell).click()
  await row.locator('input').fill(value)
  await row.locator('input').press('Enter')
  await expect(row.locator('input')).toHaveCount(0)
}

test.describe('ИИ', () => {
  test('помощник отвечает на вопрос-пример', async ({ page }) => {
    await login(page, USERS.oleg)
    await page.getByRole('button', { name: 'Спросить ИИ' }).click()

    const panel = page.getByRole('dialog', { name: 'ИИ-помощник' })
    await expect(panel).toBeVisible()
    await panel.getByRole('button', { name: 'Что такое run rate и как он считается?' }).click()

    await expect(panel.getByText('Что такое run rate и как он считается?')).toBeVisible()
    await expect(panel.getByText(/Тестовый ответ ИИ/)).toBeVisible()

    // A typed follow-up goes with the conversation; "New conversation" clears it.
    await panel.getByPlaceholder('Ваш вопрос…').fill('А для лидов?')
    await panel.getByPlaceholder('Ваш вопрос…').press('Enter')
    await expect(panel.getByText(/Тестовый ответ ИИ/)).toHaveCount(2)
    await panel.getByRole('button', { name: 'Новый разговор' }).click()
    await expect(panel.getByText(/Тестовый ответ ИИ/)).toHaveCount(0)
  })

  test('разбор недели в кабинете формируется и сохраняется', async ({ page }) => {
    await login(page, USERS.oleg)
    const card = page.locator('section').filter({ has: page.getByRole('heading', { name: 'ИИ-разбор недели' }) })
    await card.getByRole('button', { name: 'Сформировать' }).click()

    await expect(card.getByText(/Тестовый ответ ИИ/)).toBeVisible()
    await expect(card.getByText(/Сформировано .* Олег/)).toBeVisible()

    await page.reload()
    await expect(page.locator('section').filter({ has: page.getByRole('heading', { name: 'ИИ-разбор недели' }) }).getByText(/Тестовый ответ ИИ/)).toBeVisible()
  })

  test('разбор отклонения: черновик ИИ, сохранение, задача в спринт', async ({ page }) => {
    await login(page, USERS.oleg)
    await page.goto('/rnp-week')

    // Lower is better: plan 1000 / fact 2000 = 50 % — missed, so the row invites an explanation.
    await enter(page, AD_SPEND, 0, '1000')
    await enter(page, AD_SPEND, 1, '2000')
    const row = metricRow(page, AD_SPEND)
    await row.getByRole('button', { name: 'Объяснить отклонение' }).click()

    const dialog = page.getByRole('dialog', { name: 'Разбор отклонения' })
    await dialog.getByRole('button', { name: 'Черновик от ИИ' }).click()
    await expect(dialog.getByLabel('Почему так вышло')).toHaveValue('Тестовый черновик ИИ')
    await expect(dialog.getByLabel('Что делаем')).toHaveValue('Тестовый черновик ИИ')

    await dialog.getByLabel('Почему так вышло').fill('Подняли ставки в Meta на время теста креативов')
    await dialog.getByLabel('Что делаем').fill('Вернуть ставки к 15.11')
    await dialog.getByRole('button', { name: 'В спринт' }).click()
    await expect(page.getByText('Задача добавлена в спринт этой недели')).toBeVisible()
    await dialog.getByRole('button', { name: 'Сохранить' }).click()
    await expect(dialog).toBeHidden()

    await expect(row).toContainText('Подняли ставки в Meta на время теста креативов')

    await page.goto('/sprints')
    await expect(page.locator('tbody tr').filter({ hasText: 'Вернуть ставки к 15.11' })).toBeVisible()
  })
})
