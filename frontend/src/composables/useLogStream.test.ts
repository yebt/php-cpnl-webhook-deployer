import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope, nextTick } from 'vue'
import { ApiError } from '../api/client'
import type { LogChunk, LogLine, RunStatus } from '../api/types'
import { useLogStream } from './useLogStream'

function line(id: number, text = `line ${id}`): LogLine {
  return { id, step: 0, stream: 'stdout', line: text, at: '2026-10-13 10:00:00.000' }
}

function chunk(ids: number[], status: RunStatus, complete = false): LogChunk {
  return {
    lines: ids.map((id) => line(id)),
    next_after: ids.length ? ids[ids.length - 1]! : 0,
    run_status: status,
    complete,
  }
}

/** Lets pending promise callbacks (the fetch and its handlers) settle. */
async function flush() {
  for (let i = 0; i < 5; i++) await Promise.resolve()
  await nextTick()
}

describe('useLogStream', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  function start(fetchLogs: (after: number) => Promise<LogChunk>, onUpdate = vi.fn()) {
    const scope = effectScope()
    const stream = scope.run(() => useLogStream(fetchLogs, { interval: 1500, pageSize: 3, onUpdate }))!
    return { scope, stream, onUpdate }
  }

  it('advances the cursor and appends new lines on every poll', async () => {
    const fetchLogs = vi
      .fn<(after: number) => Promise<LogChunk>>()
      .mockResolvedValueOnce(chunk([1, 2], 'running'))
      .mockResolvedValueOnce(chunk([3], 'running'))
      .mockResolvedValue(chunk([], 'running'))
    const { stream } = start(fetchLogs)

    await flush()
    expect(fetchLogs).toHaveBeenLastCalledWith(0, 3)
    expect(stream.lines.value.map((l) => l.id)).toEqual([1, 2])
    expect(stream.status.value).toBe('running')

    await vi.advanceTimersByTimeAsync(1500)
    expect(fetchLogs).toHaveBeenLastCalledWith(2, 3)
    expect(stream.lines.value.map((l) => l.id)).toEqual([1, 2, 3])

    await vi.advanceTimersByTimeAsync(1500)
    expect(fetchLogs).toHaveBeenLastCalledWith(3, 3)
  })

  it('keeps the cursor when a poll returns no lines', async () => {
    const fetchLogs = vi
      .fn<(after: number) => Promise<LogChunk>>()
      .mockResolvedValueOnce(chunk([5], 'queued'))
      .mockResolvedValueOnce({ lines: [], next_after: 5, run_status: 'queued', complete: false })
      .mockResolvedValue(chunk([], 'queued'))
    start(fetchLogs)

    await flush()
    await vi.advanceTimersByTimeAsync(1500)
    await vi.advanceTimersByTimeAsync(1500)

    expect(fetchLogs.mock.calls.map((c) => c[0])).toEqual([0, 5, 5])
  })

  it('stops polling once the run is finished and every line is read', async () => {
    const fetchLogs = vi
      .fn<(after: number) => Promise<LogChunk>>()
      .mockResolvedValueOnce(chunk([1], 'running'))
      .mockResolvedValueOnce(chunk([2], 'success', true))
    const { stream } = start(fetchLogs)

    await flush()
    await vi.advanceTimersByTimeAsync(1500)
    expect(stream.lines.value.map((l) => l.id)).toEqual([1, 2])
    expect(stream.status.value).toBe('success')
    expect(stream.polling.value).toBe(false)

    await vi.advanceTimersByTimeAsync(10_000)
    expect(fetchLogs).toHaveBeenCalledTimes(2)
  })

  it('fetches the next page immediately while a backlog remains', async () => {
    const fetchLogs = vi
      .fn<(after: number) => Promise<LogChunk>>()
      .mockResolvedValueOnce(chunk([1, 2, 3], 'success'))
      .mockResolvedValueOnce(chunk([4], 'success', true))
    const { stream } = start(fetchLogs)

    await flush()
    await vi.advanceTimersByTimeAsync(0)
    await flush()

    expect(fetchLogs.mock.calls.map((c) => c[0])).toEqual([0, 3])
    expect(stream.lines.value).toHaveLength(4)
  })

  it('notifies on new lines or a status change', async () => {
    const fetchLogs = vi
      .fn<(after: number) => Promise<LogChunk>>()
      .mockResolvedValueOnce(chunk([1], 'queued'))
      .mockResolvedValueOnce(chunk([], 'queued'))
      .mockResolvedValueOnce(chunk([], 'running'))
      .mockResolvedValue(chunk([], 'running'))
    const { onUpdate } = start(fetchLogs)

    await flush()
    await vi.advanceTimersByTimeAsync(1500)
    await vi.advanceTimersByTimeAsync(1500)

    expect(onUpdate.mock.calls.map((c) => c[0])).toEqual(['queued', 'running'])
  })

  it('stops when its scope is disposed', async () => {
    const fetchLogs = vi.fn<(after: number) => Promise<LogChunk>>().mockResolvedValue(chunk([], 'running'))
    const { scope } = start(fetchLogs)

    await flush()
    scope.stop()
    await vi.advanceTimersByTimeAsync(10_000)

    expect(fetchLogs).toHaveBeenCalledTimes(1)
  })

  it('retries transient errors but gives up on a missing run', async () => {
    const transient = vi
      .fn<(after: number) => Promise<LogChunk>>()
      .mockRejectedValueOnce(new ApiError(0, 'network_error', 'offline'))
      .mockResolvedValue(chunk([1], 'running'))
    const first = start(transient)
    await flush()
    expect(first.stream.error.value).toBe('offline')
    await vi.advanceTimersByTimeAsync(3000)
    expect(first.stream.error.value).toBeNull()
    expect(first.stream.lines.value).toHaveLength(1)
    first.scope.stop()

    const missing = vi
      .fn<(after: number) => Promise<LogChunk>>()
      .mockRejectedValue(new ApiError(404, 'not_found', 'Run not found.'))
    const second = start(missing)
    await flush()
    await vi.advanceTimersByTimeAsync(10_000)
    expect(missing).toHaveBeenCalledTimes(1)
    expect(second.stream.polling.value).toBe(false)
    expect(second.stream.error.value).toBe('Run not found.')
  })
})
