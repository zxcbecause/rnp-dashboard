import { describe, expect, it } from 'vitest'
import { withProgress } from '../sprints'
import type { SprintTask } from '../../types'

const task: SprintTask = {
  id: 1, weekStart: '2026-10-19', tag: 'ops', priority: 'P2', title: 'T', metric: { id: 5, name: 'M' },
  progressPct: 40, resultNote: 'r', link: 'https://x.test', nextStep: 'n', carriedFromWeek: null,
}

describe('withProgress', () => {
  it('keeps the task and only changes progress', () => {
    expect(withProgress(task, 75)).toEqual({ title: 'T', priority: 'P2', tag: 'ops', metricId: 5, progressPct: 75, resultNote: 'r', link: 'https://x.test', nextStep: 'n' })
  })

  it('clamps progress to 0–100', () => {
    expect(withProgress(task, 140).progressPct).toBe(100)
    expect(withProgress(task, -5).progressPct).toBe(0)
  })
})
