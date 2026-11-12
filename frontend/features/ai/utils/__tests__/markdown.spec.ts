import { describe, expect, it } from 'vitest'
import { renderMarkdown } from '../markdown'

describe('renderMarkdown', () => {
  it('renders lists, bold and paragraphs', () => {
    const html = renderMarkdown('- **Лиды**: 27 / 23\n- План выполнен\n\n**Фокус недели:** задать план.')

    expect(html).toBe('<ul><li><strong>Лиды</strong>: 27 / 23</li><li>План выполнен</li></ul><p><strong>Фокус недели:</strong> задать план.</p>')
  })

  it('escapes HTML from the model', () => {
    const html = renderMarkdown('<img src=x onerror=alert(1)> **<b>x</b>**')

    expect(html).not.toContain('<img')
    expect(html).not.toContain('<b>')
    expect(html).toContain('&lt;img src=x onerror=alert(1)&gt;')
  })

  it('keeps numbered lists apart from bullets and turns headings into bold lines', () => {
    const html = renderMarkdown('## Итог\n1. Первое\n2. Второе\n- Пункт')

    expect(html).toBe('<p><strong>Итог</strong></p><ol><li>Первое</li><li>Второе</li></ol><ul><li>Пункт</li></ul>')
  })
})
