import { describe, expect, it, vi } from 'vitest'
import { useAsyncAction } from '../useAsyncAction'
import { createLatestRequestScope } from '../useLatestRequest'

describe('useAsyncAction', () => {
  it('drops a second call while the first one is in flight', async () => {
    let release!: () => void
    const action = vi.fn(() => new Promise<string>((resolve) => {
      release = () => resolve('done')
    }))
    const { pending, run } = useAsyncAction(action)

    const first = run()
    const second = run()

    expect(pending.value).toBe(true)
    expect(action).toHaveBeenCalledTimes(1)
    await expect(second).resolves.toBeUndefined()

    release()
    await expect(first).resolves.toBe('done')
    expect(pending.value).toBe(false)
  })

  it('reports errors and swallows them when rethrow is false', async () => {
    const onError = vi.fn()
    const { pending, run } = useAsyncAction(async () => {
      throw new Error('boom')
    }, { onError, rethrow: false })

    await expect(run()).resolves.toBeUndefined()
    expect(onError).toHaveBeenCalledOnce()
    expect(pending.value).toBe(false)
  })

  it('rethrows by default', async () => {
    const { run } = useAsyncAction(async () => {
      throw new Error('boom')
    })

    await expect(run()).rejects.toThrow('boom')
  })
})

describe('createLatestRequestScope', () => {
  it('marks every request but the newest as stale', () => {
    const scope = createLatestRequestScope()
    const first = scope.begin()
    const second = scope.begin()

    expect(first()).toBe(false)
    expect(second()).toBe(true)
  })
})
