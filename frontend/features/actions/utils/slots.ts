import type { ActionSlot, Periodicity, RoadmapItem, RoadmapItemInput } from '../types'

const WEEKDAYS = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб']
const MONTHS = ['январь', 'февраль', 'март', 'апрель', 'май', 'июнь', 'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь']
const QUARTERS = ['I', 'II', 'III', 'IV']

/** Short label of a check slot: "Пн 26.10", "нед. 26.10", "октябрь", "IV кв." */
export function slotLabel(periodicity: Periodicity, slot: Pick<ActionSlot, 'periodStart'>): string {
  const [y, m, d] = slot.periodStart.split('-').map(Number) as [number, number, number]
  const dm = `${String(d).padStart(2, '0')}.${String(m).padStart(2, '0')}`

  switch (periodicity) {
    case 'daily':
      return `${WEEKDAYS[new Date(y, m - 1, d).getDay()]} ${dm}`
    case 'weekly':
      return `нед. ${dm}`
    case 'monthly':
      return MONTHS[m - 1] ?? dm
    case 'quarterly':
      return `${QUARTERS[Math.floor((m - 1) / 3)]} кв.`
  }
}

/**
 * Click cycle of a check: no mark → done → not done → no mark.
 * Returns the value to send (null removes the mark).
 */
export function nextCheckValue(current: boolean | null): boolean | null {
  if (current === null) return true
  if (current) return false
  return null
}

/** Full PUT body for a roadmap item where only the status changes (what an item owner may do). */
export function withStatus(item: RoadmapItem, status: RoadmapItem['status']): RoadmapItemInput {
  return {
    title: item.title,
    ownerUserId: item.owner?.id ?? null,
    dueDate: item.dueDate,
    status,
    expectedEffect: item.expectedEffect,
  }
}
