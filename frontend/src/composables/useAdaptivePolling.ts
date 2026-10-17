import { onScopeDispose } from 'vue'

/** Page visibility, injectable for tests. */
export interface VisibilitySource {
  isHidden(): boolean
  /** Calls `listener` on every visibility change; returns the unsubscribe function. */
  subscribe(listener: () => void): () => void
}

export const documentVisibility: VisibilitySource = {
  isHidden: () => typeof document !== 'undefined' && document.visibilityState === 'hidden',
  subscribe(listener) {
    document.addEventListener('visibilitychange', listener)
    return () => document.removeEventListener('visibilitychange', listener)
  },
}

export interface AdaptivePollingOptions {
  /** Delay after a load while `isActive()` is true (something queued/running). */
  activeMs: number
  /** Delay after a load otherwise. */
  idleMs: number
  /** Read after each load to choose the next delay. */
  isActive: () => boolean
  visibility?: VisibilitySource
}

/**
 * Runs `load` now and again after each completed load: every `activeMs` while
 * `isActive()`, every `idleMs` otherwise. Pauses while the tab is hidden and loads as
 * soon as it is visible again. `refresh()` loads immediately (queued if a load is in
 * flight, never two at once). Stops with the calling scope.
 */
export function useAdaptivePolling(load: () => Promise<unknown>, options: AdaptivePollingOptions) {
  const visibility = options.visibility ?? documentVisibility
  let timer: ReturnType<typeof setTimeout> | null = null
  let inFlight = false
  let rerun = false
  let stopped = false

  function clear() {
    if (timer !== null) clearTimeout(timer)
    timer = null
  }

  function schedule() {
    clear()
    if (stopped || visibility.isHidden()) return
    timer = setTimeout(() => void run(), options.isActive() ? options.activeMs : options.idleMs)
  }

  async function run(): Promise<void> {
    clear()
    if (stopped) return
    if (inFlight) {
      rerun = true
      return
    }
    inFlight = true
    try {
      await load()
    } catch {
      // The loader owns error state; polling just carries on.
    } finally {
      inFlight = false
    }
    if (stopped) return
    if (rerun) {
      rerun = false
      void run()
      return
    }
    schedule()
  }

  function refresh() {
    if (stopped || visibility.isHidden()) return
    void run()
  }

  const unsubscribe = visibility.subscribe(() => {
    if (visibility.isHidden()) clear()
    else void run()
  })

  onScopeDispose(() => {
    stopped = true
    clear()
    unsubscribe()
  })

  if (!visibility.isHidden()) void run()

  return { refresh }
}
