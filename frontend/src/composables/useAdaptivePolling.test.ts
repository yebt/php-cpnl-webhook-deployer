import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope } from 'vue'
import { useAdaptivePolling, type VisibilitySource } from './useAdaptivePolling'

function fakeVisibility(initiallyHidden = false) {
  let hidden = initiallyHidden
  const listeners = new Set<() => void>()
  const source: VisibilitySource = {
    isHidden: () => hidden,
    subscribe(listener) {
      listeners.add(listener)
      return () => listeners.delete(listener)
    },
  }
  return {
    source,
    listeners,
    set(value: boolean) {
      hidden = value
      listeners.forEach((l) => l())
    },
  }
}

async function flush() {
  for (let i = 0; i < 5; i++) await Promise.resolve()
}

describe('useAdaptivePolling', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  function setup(options: { active?: () => boolean; hidden?: boolean; load?: () => Promise<void> } = {}) {
    const load = vi.fn(options.load ?? (() => Promise.resolve()))
    const visibility = fakeVisibility(options.hidden)
    const scope = effectScope()
    const polling = scope.run(() =>
      useAdaptivePolling(load, {
        activeMs: 3000,
        idleMs: 30000,
        isActive: options.active ?? (() => false),
        visibility: visibility.source,
      }),
    )!
    return { load, visibility, scope, polling }
  }

  it('loads right away, then every idleMs while nothing is active', async () => {
    const { load } = setup()
    await flush()
    expect(load).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(29_999)
    expect(load).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(1)
    expect(load).toHaveBeenCalledTimes(2)
  })

  it('polls every activeMs while something is queued or running, and slows down once it is done', async () => {
    let active = true
    const { load } = setup({ active: () => active })
    await flush()

    await vi.advanceTimersByTimeAsync(3000)
    expect(load).toHaveBeenCalledTimes(2)
    await vi.advanceTimersByTimeAsync(3000)
    expect(load).toHaveBeenCalledTimes(3)

    active = false
    await vi.advanceTimersByTimeAsync(3000)
    expect(load).toHaveBeenCalledTimes(4) // the load that saw "done"
    await vi.advanceTimersByTimeAsync(29_000)
    expect(load).toHaveBeenCalledTimes(4)
    await vi.advanceTimersByTimeAsync(1000)
    expect(load).toHaveBeenCalledTimes(5)
  })

  it('pauses while the tab is hidden and refreshes as soon as it is visible again', async () => {
    const { load, visibility } = setup({ active: () => true })
    await flush()

    visibility.set(true)
    await vi.advanceTimersByTimeAsync(60_000)
    expect(load).toHaveBeenCalledTimes(1)

    visibility.set(false)
    await flush()
    expect(load).toHaveBeenCalledTimes(2)
    await vi.advanceTimersByTimeAsync(3000)
    expect(load).toHaveBeenCalledTimes(3)
  })

  it('does not load at start while hidden', async () => {
    const { load, visibility } = setup({ hidden: true })
    await vi.advanceTimersByTimeAsync(60_000)
    expect(load).not.toHaveBeenCalled()
    visibility.set(false)
    await flush()
    expect(load).toHaveBeenCalledTimes(1)
  })

  it('refresh() loads immediately and restarts the interval', async () => {
    const { load, polling } = setup()
    await flush()
    await vi.advanceTimersByTimeAsync(20_000)

    polling.refresh()
    await flush()
    expect(load).toHaveBeenCalledTimes(2)
    await vi.advanceTimersByTimeAsync(29_999)
    expect(load).toHaveBeenCalledTimes(2)
    await vi.advanceTimersByTimeAsync(1)
    expect(load).toHaveBeenCalledTimes(3)
  })

  it('never runs two loads at once: a refresh during a load runs once it settles', async () => {
    let release: () => void = () => undefined
    const { load, polling } = setup({ load: () => new Promise<void>((r) => (release = r)) })
    await flush()
    polling.refresh()
    polling.refresh()
    await flush()
    expect(load).toHaveBeenCalledTimes(1)

    release()
    await flush()
    expect(load).toHaveBeenCalledTimes(2)
  })

  it('keeps polling after a failed load', async () => {
    const load = vi.fn().mockRejectedValueOnce(new Error('offline')).mockResolvedValue(undefined)
    const { scope } = (() => {
      const visibility = fakeVisibility()
      const scope = effectScope()
      scope.run(() => useAdaptivePolling(load, { activeMs: 3000, idleMs: 30000, isActive: () => false, visibility: visibility.source }))
      return { scope }
    })()
    await flush()
    await vi.advanceTimersByTimeAsync(30_000)
    expect(load).toHaveBeenCalledTimes(2)
    scope.stop()
  })

  it('stops with its scope and unsubscribes from visibility changes', async () => {
    const { load, scope, visibility } = setup()
    await flush()
    scope.stop()
    expect(visibility.listeners.size).toBe(0)
    await vi.advanceTimersByTimeAsync(120_000)
    expect(load).toHaveBeenCalledTimes(1)
  })
})
