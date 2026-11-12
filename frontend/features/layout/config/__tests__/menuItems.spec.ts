import { describe, expect, it } from 'vitest'
import { menuItemFor } from '../menuItems'

describe('menuItemFor', () => {
  it('finds the section of nested pages', () => {
    expect(menuItemFor('/metrics/12')?.label).toBe('nav.metrics')
    expect(menuItemFor('/admin/tokens')?.label).toBe('nav.tokens')
    expect(menuItemFor('/rnp-week')?.label).toBe('nav.rnpWeek')
  })

  it('does not match a mere prefix of another word', () => {
    expect(menuItemFor('/metricsx')).toBeNull()
    expect(menuItemFor('/login')).toBeNull()
  })
})
