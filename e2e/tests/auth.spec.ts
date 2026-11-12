import { expect, test } from '@playwright/test'
import { USERS, login } from './helpers'

test.describe('Вход и сессия', () => {
  test('без входа приложение открывает страницу входа', async ({ page }) => {
    await page.goto('/cabinet')
    await expect(page).toHaveURL(/\/login/)
    await expect(page.getByRole('heading', { name: 'Вход' })).toBeVisible()
  })

  test('неверный пароль — понятная ошибка, без входа', async ({ page }) => {
    await page.goto('/login')
    await page.locator('#email').fill(USERS.oleg)
    await page.locator('#password').fill('wrong-password-123')
    await page.getByRole('button', { name: 'Войти' }).click()

    await expect(page.getByText('Неверный email или пароль')).toBeVisible()
    await expect(page).toHaveURL(/\/login/)
  })

  test('вход, сессия переживает перезагрузку, выход', async ({ page }) => {
    await login(page, USERS.oleg)
    await expect(page).toHaveURL(/\/cabinet/)
    await expect(page.getByText('Обзор')).toBeVisible()

    // The access token lives in memory only: after a reload the session is restored from the refresh cookie.
    await page.reload()
    await expect(page.getByRole('button', { name: /меню пользователя/i })).toBeVisible()
    await expect(page).toHaveURL(/\/cabinet/)

    await page.getByRole('button', { name: /меню пользователя/i }).click()
    await page.getByRole('menuitem', { name: 'Выйти' }).click()
    await expect(page).toHaveURL(/\/login/)

    // Signed out for real: protected pages lead back to the login form.
    await page.goto('/rnp-week')
    await expect(page).toHaveURL(/\/login/)
  })

  test('несколько вкладок открываются одновременно — сессия не теряется', async ({ context, page }) => {
    await login(page, USERS.oleg)
    expect(await page.evaluate(() => window.isSecureContext && 'locks' in navigator), 'Web Locks need a secure context').toBe(true)
    const tabs = [page, await context.newPage(), await context.newPage(), await context.newPage()]

    // Every tab restores its session from the single-use refresh cookie at the same moment
    // (like a browser restoring its tabs): the refreshes must not race each other.
    for (let round = 0; round < 3; round++) {
      await Promise.all(tabs.map(tab => tab.goto('/rnp-week')))
      for (const tab of tabs) {
        await expect(tab.getByRole('button', { name: /меню пользователя/i })).toBeVisible()
      }
    }
  })

  test('выход в одной вкладке завершает сессию в остальных', async ({ context, page }) => {
    await login(page, USERS.oleg)
    const other = await context.newPage()
    await other.goto('/rnp-week')
    await expect(other.getByRole('button', { name: /меню пользователя/i })).toBeVisible()

    await page.getByRole('button', { name: /меню пользователя/i }).click()
    await page.getByRole('menuitem', { name: 'Выйти' }).click()
    await expect(page).toHaveURL(/\/login/)

    // Without touching the other tab: it leaves by itself.
    await expect(other).toHaveURL(/\/login/)
  })
})
