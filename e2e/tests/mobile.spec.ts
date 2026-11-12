import { expect, test } from '@playwright/test'
import { USERS, login } from './helpers'

const EDIT = 'Нажмите, чтобы ввести значение'
const AD_SPEND = 'Расход на рекламу'

test.use({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true })

test.describe('Телефон', () => {
  test('меню-шторка и ввод в карточке РНП', async ({ page }) => {
    await login(page, USERS.oleg)
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBe(0)

    // The menu is a drawer: closed until the button, closed again after navigating.
    const menuLink = page.getByRole('link', { name: 'РНП-неделя', exact: true })
    await expect(menuLink).toBeHidden()
    await page.getByRole('button', { name: 'Открыть меню' }).click()
    await menuLink.click()
    await expect(page).toHaveURL(/\/rnp-week/)
    await expect(menuLink).toBeHidden()

    // A card per metric instead of the wide table.
    await expect(page.locator('table')).toHaveCount(0)
    const card = page.locator('li').filter({ has: page.getByRole('link', { name: AD_SPEND, exact: true }) })
    await expect(card).toBeVisible()

    // Week plan (second editable value in the week block), then the fact (first): lower is better → 200 %.
    const week = card.locator('div.rounded-xl').first()
    await week.getByTitle(EDIT).nth(1).click()
    await card.locator('input').fill('2000')
    await card.locator('input').press('Enter')
    await expect(card.locator('input')).toHaveCount(0)
    await week.getByTitle(EDIT).first().click()
    await card.locator('input').fill('1000')
    await card.locator('input').press('Enter')
    await expect(card.locator('input')).toHaveCount(0)
    await expect(week.getByText(/^200(,0)?[\s  ]?%$/)).toBeVisible()
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBe(0)
  })
})
