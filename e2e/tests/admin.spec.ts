import { expect, test } from '@playwright/test'
import { USERS, login } from './helpers'

test.describe('Администрирование', () => {
  test('новый сотрудник: учётка → руководитель нового отдела → вход с выданным паролем', async ({ page, browser }) => {
    await login(page, USERS.admin)

    // Account: the password is shown once.
    await page.getByRole('link', { name: 'Пользователи' }).click()
    await expect(page).toHaveURL(/\/admin\/users/)
    await page.getByRole('button', { name: 'Добавить пользователя' }).click()
    const createUser = page.getByRole('dialog')
    await createUser.getByLabel('Email').fill('maria@rnp.test')
    await createUser.getByLabel('Имя').fill('María Test')
    await createUser.getByRole('button', { name: 'Добавить' }).click()
    await expect(createUser).toBeHidden()
    await expect(page.getByText('Пароль для maria@rnp.test')).toBeVisible()
    const password = (await page.getByTestId('issued-password').textContent())?.trim() ?? ''
    expect(password).toMatch(/^[A-Za-z2-9]{14}$/)
    await expect(page.locator('tbody tr').filter({ hasText: 'maria@rnp.test' })).toContainText('нет отдела')

    // Unit under Customer Service, headed by the new person.
    await page.getByRole('link', { name: 'Оргструктура' }).click()
    await page.getByRole('button', { name: 'Добавить отдел' }).click()
    const createUnit = page.getByRole('dialog')
    await createUnit.getByLabel('Название').fill('Base calls')
    await createUnit.getByRole('combobox').click()
    await page.getByRole('option', { name: /Customer Service$/ }).click()
    await createUnit.getByRole('button', { name: 'Создать' }).click()
    await expect(createUnit).toBeHidden()

    const unitRow = page.getByTestId('unit-Base calls')
    await expect(unitRow).toContainText('без руководителя')
    await unitRow.getByRole('button', { name: 'Настроить' }).click()
    const dialog = page.getByRole('dialog')
    await dialog.getByRole('combobox', { name: 'Руководитель' }).click()
    await page.getByRole('option', { name: /maria@rnp\.test/ }).click()
    await expect(dialog.getByText('Руководитель', { exact: true }).last()).toBeVisible()
    await expect(dialog).toContainText('María Test')
    await page.keyboard.press('Escape')
    await expect(unitRow).toContainText('María Test')

    // The new head signs in with the issued password and sees exactly the new unit.
    const context = await browser.newContext()
    const maria = await context.newPage()
    await login(maria, 'maria@rnp.test', password)
    await maria.goto('/rnp-week')
    await expect(maria.getByText('Base calls').first()).toBeVisible()
    await expect(maria.getByRole('link', { name: 'Пользователи' })).toHaveCount(0)
    await context.close()
  })
})
