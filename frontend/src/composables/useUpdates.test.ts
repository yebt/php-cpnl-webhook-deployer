import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope, nextTick } from 'vue'
import { ApiError } from '../api/client'
import type { UpdateResult, UpdateStatus } from '../api/types'
import { useUpdates, type UpdatesClient } from './useUpdates'

function status(overrides: Partial<UpdateStatus> = {}): UpdateStatus {
  return {
    current: '1.0.0',
    current_is_dev: false,
    available: true,
    can_apply: true,
    active_runs: 0,
    latest: {
      version: '1.1.0',
      tag: 'v1.1.0',
      name: 'v1.1.0',
      url: 'https://github.com/yebt/php-cpnl-webhook-deployer/releases/tag/v1.1.0',
      published_at: '2026-10-01T00:00:00Z',
      notes_excerpt: 'Fixes',
    },
    ...overrides,
  }
}

const applied: UpdateResult = { updated: true, from: '1.0.0', to: '1.1.0', backup_file: 'index.php.bak' }

async function flush() {
  for (let i = 0; i < 5; i++) await Promise.resolve()
  await nextTick()
}

describe('useUpdates', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  type Question = { title: string; message: string }
  function setup(
    client: UpdatesClient,
    confirm = vi.fn((_question: Question): boolean | Promise<boolean> => true),
  ) {
    const reloadPage = vi.fn()
    const scope = effectScope()
    const updates = scope.run(() => useUpdates({ client, confirm, reloadPage, reloadDelayMs: 2000 }))!
    return { updates, confirm, reloadPage, scope }
  }

  it('loads the update status', async () => {
    const { updates } = setup({ status: vi.fn().mockResolvedValue(status()), apply: vi.fn() })
    await flush()

    expect(updates.status.value?.latest?.version).toBe('1.1.0')
    expect(updates.canUpdate.value).toBe(true)
  })

  it('cannot update when nothing is available, from source, or for dev builds', async () => {
    for (const s of [
      status({ available: false }),
      status({ can_apply: false }),
      status({ current: 'dev', current_is_dev: true }),
    ]) {
      const { updates } = setup({ status: vi.fn().mockResolvedValue(s), apply: vi.fn() })
      await flush()
      expect(updates.canUpdate.value).toBe(false)
    }
  })

  it('asks for confirmation and applies, then reloads the page', async () => {
    const apply = vi.fn().mockResolvedValue(applied)
    const { updates, confirm, reloadPage } = setup({ status: vi.fn().mockResolvedValue(status()), apply })
    await flush()

    await updates.apply()

    expect(confirm.mock.calls[0]![0].title).toBe('Update Deployer from 1.0.0 to 1.1.0?')
    expect(apply).toHaveBeenCalledOnce()
    expect(updates.result.value).toEqual(applied)
    expect(reloadPage).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(2000)
    expect(reloadPage).toHaveBeenCalledOnce()
  })

  it('warns about active runs in the confirmation', async () => {
    const { updates, confirm } = setup(
      { status: vi.fn().mockResolvedValue(status({ active_runs: 2 })), apply: vi.fn().mockResolvedValue(applied) },
    )
    await flush()

    await updates.apply()

    expect(confirm.mock.calls[0]![0].message).toContain('2 runs are queued or running')
  })

  it('does nothing when the confirmation is declined', async () => {
    const apply = vi.fn()
    const { updates } = setup({ status: vi.fn().mockResolvedValue(status()), apply }, vi.fn(() => false))
    await flush()

    await updates.apply()

    expect(apply).not.toHaveBeenCalled()
    expect(updates.applying.value).toBe(false)
  })

  it('waits for an asynchronous confirmation (the in-app dialog) and asks only once', async () => {
    const apply = vi.fn().mockResolvedValue(applied)
    let answer: (value: boolean) => void = () => {}
    const confirm = vi.fn(() => new Promise<boolean>((resolve) => (answer = resolve)))
    const { updates } = setup({ status: vi.fn().mockResolvedValue(status()), apply }, confirm)
    await flush()

    const first = updates.apply()
    void updates.apply()
    expect(confirm).toHaveBeenCalledOnce()
    expect(apply).not.toHaveBeenCalled()

    answer(true)
    await first
    expect(apply).toHaveBeenCalledOnce()
  })

  it('shows the refusal message and does not reload', async () => {
    const apply = vi.fn().mockRejectedValue(new ApiError(409, 'checksum_mismatch', 'Checksum mismatch; the installed file was not touched.'))
    const { updates, reloadPage } = setup({ status: vi.fn().mockResolvedValue(status()), apply })
    await flush()

    await updates.apply()
    await vi.advanceTimersByTimeAsync(5000)

    expect(updates.applyError.value).toBe('Checksum mismatch; the installed file was not touched.')
    expect(updates.applying.value).toBe(false)
    expect(reloadPage).not.toHaveBeenCalled()
  })

  it('does not reload when the server reports nothing changed', async () => {
    const apply = vi.fn().mockResolvedValue({ ...applied, updated: false, backup_file: null })
    const { updates, reloadPage } = setup({ status: vi.fn().mockResolvedValue(status()), apply })
    await flush()

    await updates.apply()
    await vi.advanceTimersByTimeAsync(5000)

    expect(reloadPage).not.toHaveBeenCalled()
  })
})
