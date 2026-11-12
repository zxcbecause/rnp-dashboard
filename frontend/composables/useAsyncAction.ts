import { ref } from 'vue'
import type { Ref } from 'vue'

export interface UseAsyncActionOptions {
  /** Called for every rejected execution. Dropped duplicates never reach the action. */
  onError?: (error: unknown) => void
  /** Extra precondition (form validity, selection...) checked before taking the lock. */
  canRun?: () => boolean
  /** Re-throw after onError (default true) so callers keep their error contracts. */
  rethrow?: boolean
}

export interface UseAsyncActionResult<Args extends unknown[], R> {
  /** true while the action is in flight; bind it to :loading AND :disabled. */
  pending: Ref<boolean>
  /** Runs the action unless it is already running; dropped calls resolve to undefined. */
  run: (...args: Args) => Promise<R | undefined>
}

/**
 * Single-flight wrapper for user-triggered mutations. The guard is synchronous, so a double
 * click is dropped even before Vue re-renders the disabled button.
 */
export function useAsyncAction<Args extends unknown[], R>(
  action: (...args: Args) => Promise<R>,
  options: UseAsyncActionOptions = {},
): UseAsyncActionResult<Args, R> {
  const pending = ref(false)

  const run = async (...args: Args): Promise<R | undefined> => {
    if (pending.value) return undefined
    if (options.canRun && !options.canRun()) return undefined

    pending.value = true
    try {
      return await action(...args)
    }
    catch (error) {
      options.onError?.(error)
      if (options.rethrow === false) return undefined
      throw error
    }
    finally {
      pending.value = false
    }
  }

  return { pending, run }
}
