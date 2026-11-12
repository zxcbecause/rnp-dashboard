export type MetricUnit = 'eur' | 'count' | 'percent' | 'minutes' | 'days' | 'ratio' | 'boolean' | 'score'

/** Non-breaking space: keeps a number and its unit on one line. */
const NBSP = String.fromCharCode(0xA0)

const UNIT_SUFFIX: Partial<Record<MetricUnit, string>> = {
  eur: NBSP + '€',
  percent: NBSP + '%',
  minutes: NBSP + 'мин',
  days: NBSP + 'дн.',
}

/**
 * Moves the decimal point of a decimal string without floating point: shift(“0.45”, 2) = “45”.
 */
export function shiftDecimal(value: string, places: number): string {
  const negative = value.startsWith('-')
  const [intPart = '0', fracPart = ''] = (negative ? value.slice(1) : value).split('.')
  let digits = intPart + fracPart
  let point = intPart.length + places

  if (point < 0) {
    digits = '0'.repeat(-point) + digits
    point = 0
  }
  if (point > digits.length) {
    digits = digits + '0'.repeat(point - digits.length)
  }

  let whole = digits.slice(0, point).replace(/^0+(?=\d)/, '') || '0'
  const frac = digits.slice(point).replace(/0+$/, '')
  if (whole === '') whole = '0'
  const result = frac ? `${whole}.${frac}` : whole

  return negative && /[1-9]/.test(result) ? `-${result}` : result
}

function groupThousands(value: string, decimals: number): string {
  const number = Number(value)
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: decimals, minimumFractionDigits: 0 }).format(number)
}

/** Display value: "141 200 €", "45 %", "5 дн.", "Да". Empty string for no value. */
export function formatMetricValue(unit: MetricUnit, value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') return ''

  switch (unit) {
    case 'boolean':
      return value === '1' ? 'Да' : value === '0' ? 'Нет' : value
    case 'percent':
      return groupThousands(shiftDecimal(value, 2), 1) + UNIT_SUFFIX.percent
    case 'eur':
      return groupThousands(value, 0) + UNIT_SUFFIX.eur
    case 'count':
      return groupThousands(value, 0)
    case 'ratio':
      return groupThousands(value, 2)
    default:
      return groupThousands(value, 1) + (UNIT_SUFFIX[unit] ?? '')
  }
}

export function formatPct(value: string | null | undefined): string {
  return value === null || value === undefined ? '' : `${groupThousands(value, 1)}${NBSP}%`
}

/** Value as the user edits it: percents in percent points, everything else unchanged. */
export function toInputValue(unit: MetricUnit, value: string | null | undefined): string {
  if (value === null || value === undefined) return ''
  return unit === 'percent' ? shiftDecimal(value, 2) : value
}

/**
 * User input → API value. Accepts "," as decimal separator and spaces as thousands separators;
 * anything else is sent as is so the backend can reject it with a clear message.
 * Empty input means "remove the value" (null).
 */
export function fromInputValue(unit: MetricUnit, input: string): string | null {
  const trimmed = input.trim()
  if (trimmed === '') return null

  if (unit === 'boolean') {
    const lowered = trimmed.toLowerCase()
    if (['да', 'yes', '1'].includes(lowered)) return '1'
    if (['нет', 'no', '0'].includes(lowered)) return '0'
    return trimmed
  }

  const normalized = trimmed.replace(/\s/g, '').replaceAll(NBSP, '').replace(',', '.')
  if (!/^-?\d+(\.\d+)?$/.test(normalized)) return trimmed

  return unit === 'percent' ? shiftDecimal(normalized, -2) : normalized
}

export function inputHint(unit: MetricUnit): string {
  switch (unit) {
    case 'percent': return 'в процентах, например 45 или 12,5'
    case 'count': return 'целое число'
    case 'boolean': return 'да / нет'
    case 'eur': return 'сумма в евро'
    case 'days': return 'дней'
    case 'minutes': return 'минут'
    case 'score': return 'от −100 до 100'
    default: return 'число'
  }
}
