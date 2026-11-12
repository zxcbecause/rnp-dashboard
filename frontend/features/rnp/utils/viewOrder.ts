export interface ViewEntry {
  metricId: number
  name: string
  visible: boolean
}

/** Returns a copy with the entry at `index` moved by `delta` (−1 up, +1 down); out of range → unchanged. */
export function moveEntry<T>(list: T[], index: number, delta: number): T[] {
  const target = index + delta
  if (index < 0 || index >= list.length || target < 0 || target >= list.length) return list
  const copy = [...list]
  const [item] = copy.splice(index, 1)
  copy.splice(target, 0, item as T)
  return copy
}

/** At least one metric must stay visible (the backend rejects hiding everything). */
export function canSaveView(entries: ViewEntry[]): boolean {
  return entries.some(entry => entry.visible)
}
