import { describe, expect, it } from 'vitest'
import { formatMetricValue, formatPct, fromInputValue, shiftDecimal, toInputValue } from '../metricFormat'

const nbsp = (s: string) => s.replace(/ /g, ' ')

describe('shiftDecimal', () => {
  it('moves the decimal point without float errors', () => {
    expect(shiftDecimal('0.45', 2)).toBe('45')
    expect(shiftDecimal('0.9776', 2)).toBe('97.76')
    expect(shiftDecimal('45', -2)).toBe('0.45')
    expect(shiftDecimal('12.5', -2)).toBe('0.125')
    expect(shiftDecimal('0.07', -2)).toBe('0.0007')
    expect(shiftDecimal('1', 2)).toBe('100')
    expect(shiftDecimal('0', 2)).toBe('0')
    expect(shiftDecimal('-0.5', 2)).toBe('-50')
  })
})

describe('formatMetricValue', () => {
  it('formats by unit', () => {
    expect(formatMetricValue('eur', '141200')).toBe(nbsp('141 200 €'))
    expect(formatMetricValue('percent', '0.4500')).toBe(nbsp('45 %'))
    expect(formatMetricValue('percent', '0.9776')).toBe(nbsp('97,8 %'))
    expect(formatMetricValue('count', '1500')).toBe(nbsp('1 500'))
    expect(formatMetricValue('days', '5.5')).toBe(nbsp('5,5 дн.'))
    expect(formatMetricValue('boolean', '1')).toBe('Да')
    expect(formatMetricValue('boolean', '0')).toBe('Нет')
    expect(formatMetricValue('eur', null)).toBe('')
    expect(formatMetricValue('count', '0')).toBe('0')
  })

  it('formats completion percent', () => {
    expect(formatPct('90')).toBe(nbsp('90 %'))
    expect(formatPct('23.5')).toBe(nbsp('23,5 %'))
    expect(formatPct(null)).toBe('')
  })
})

describe('input conversion', () => {
  it('shows percents in percent points', () => {
    expect(toInputValue('percent', '0.45')).toBe('45')
    expect(toInputValue('eur', '1500.5')).toBe('1500.5')
    expect(toInputValue('count', null)).toBe('')
  })

  it('normalizes user input for the API', () => {
    expect(fromInputValue('percent', '45')).toBe('0.45')
    expect(fromInputValue('percent', '12,5')).toBe('0.125')
    expect(fromInputValue('eur', '35 000,50')).toBe('35000.50')
    expect(fromInputValue('count', ' 12 ')).toBe('12')
    expect(fromInputValue('boolean', 'Да')).toBe('1')
    expect(fromInputValue('boolean', 'нет')).toBe('0')
    expect(fromInputValue('count', '')).toBeNull()
  })

  it('passes garbage through for the backend to reject', () => {
    expect(fromInputValue('eur', '€35 000')).toBe('€35 000')
    expect(fromInputValue('percent', '🔴 : 48%')).toBe('🔴 : 48%')
  })
})
