import { afterEach, describe, expect, it, vi } from 'vitest'
import { broadcastSignOut, onSignedOutElsewhere, setCrossTabEnvironment, withRefreshLock } from '../crossTab'

/** Exclusive lock like navigator.locks: callbacks of one name run one after another. */
function fakeLocks() {
  const queues = new Map<string, Promise<unknown>>()
  return {
    request: ((name: string, callback: () => Promise<unknown>) => {
      const previous = queues.get(name) ?? Promise.resolve()
      const run = previous.then(() => callback())
      queues.set(name, run.catch(() => undefined))
      return run
    }) as unknown as LockManager['request'],
  }
}

/** BroadcastChannel bus: a message reaches every other open channel, not the sender. */
function fakeChannels() {
  const open = new Set<{ listeners: ((event: MessageEvent) => void)[] }>()
  return () => {
    const self = { listeners: [] as ((event: MessageEvent) => void)[] }
    open.add(self)
    return {
      postMessage: (data: unknown) => {
        for (const other of open) {
          if (other !== self) other.listeners.forEach(listener => listener({ data } as MessageEvent))
        }
      },
      addEventListener: ((_type: string, listener: (event: MessageEvent) => void) => {
        self.listeners.push(listener)
      }) as BroadcastChannel['addEventListener'],
      close: () => {
        open.delete(self)
      },
    }
  }
}

const deferred = () => {
  let resolve!: (value: string) => void
  const promise = new Promise<string>((r) => {
    resolve = r
  })
  return { promise, resolve }
}

afterEach(() => setCrossTabEnvironment(null))

describe('withRefreshLock', () => {
  it('runs refreshes of different tabs one after another', async () => {
    setCrossTabEnvironment({ locks: fakeLocks(), createChannel: null })
    const first = deferred()
    const order: string[] = []

    const a = withRefreshLock(async () => {
      order.push('a:start')
      const token = await first.promise
      order.push('a:end')
      return token
    })
    const b = withRefreshLock(async () => {
      order.push('b:start')
      return 'token-b'
    })

    await Promise.resolve()
    expect(order).toEqual(['a:start'])
    first.resolve('token-a')

    await expect(a).resolves.toBe('token-a')
    await expect(b).resolves.toBe('token-b')
    expect(order).toEqual(['a:start', 'a:end', 'b:start'])
  })

  it('releases the lock when a refresh fails', async () => {
    setCrossTabEnvironment({ locks: fakeLocks(), createChannel: null })
    await expect(withRefreshLock(() => Promise.reject(new Error('401')))).rejects.toThrow('401')
    await expect(withRefreshLock(() => Promise.resolve('next'))).resolves.toBe('next')
  })

  it('runs directly without Web Locks', async () => {
    setCrossTabEnvironment({ locks: null, createChannel: null })
    await expect(withRefreshLock(() => Promise.resolve('direct'))).resolves.toBe('direct')
  })
})

describe('sign-out broadcast', () => {
  it('reaches the other tabs, not the one that signed out', () => {
    setCrossTabEnvironment({ locks: null, createChannel: fakeChannels() })
    const other = vi.fn()
    const unsubscribe = onSignedOutElsewhere(other)

    broadcastSignOut()
    expect(other).toHaveBeenCalledTimes(1)

    unsubscribe()
    broadcastSignOut()
    expect(other).toHaveBeenCalledTimes(1)
  })

  it('does nothing without BroadcastChannel', () => {
    setCrossTabEnvironment({ locks: null, createChannel: null })
    const listener = vi.fn()
    onSignedOutElsewhere(listener)()
    expect(() => broadcastSignOut()).not.toThrow()
    expect(listener).not.toHaveBeenCalled()
  })
})
