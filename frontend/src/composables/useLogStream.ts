import { onScopeDispose, ref, shallowRef } from 'vue'
import { ApiError } from '../api/client'
import type { LogChunk, LogLine, RunStatus } from '../api/types'

export interface LogStreamOptions {
  /** Delay between polls while the run is queued or running (ms). */
  interval?: number
  /** Page size requested from the API; a full page means a backlog, fetched right away. */
  pageSize?: number
  /** Called after a poll that brought new lines or a status change. */
  onUpdate?: (status: RunStatus) => void
}

/**
 * Incrementally reads a run's log by cursor (`after` = id of the last line seen).
 * Polls every `interval` ms until the API reports the run finished with every line read,
 * then stops. Stops for good on 401/404, retries anything else. Cleans up with its scope.
 */
export function useLogStream(fetchLogs: (after: number, limit: number) => Promise<LogChunk>, options: LogStreamOptions = {}) {
  const interval = options.interval ?? 1500
  const pageSize = options.pageSize ?? 500

  const lines = shallowRef<LogLine[]>([])
  const status = ref<RunStatus | null>(null)
  const error = ref<string | null>(null)
  const polling = ref(true)

  let cursor = 0
  let timer: ReturnType<typeof setTimeout> | null = null
  let stopped = false

  function schedule(delay: number) {
    if (stopped) return
    timer = setTimeout(() => void poll(), delay)
  }

  async function poll() {
    timer = null
    if (stopped) return
    try {
      const chunk = await fetchLogs(cursor, pageSize)
      if (stopped) return
      error.value = null
      const statusChanged = chunk.run_status !== status.value
      status.value = chunk.run_status
      if (chunk.lines.length > 0) {
        lines.value = [...lines.value, ...chunk.lines]
        cursor = Math.max(cursor, chunk.next_after)
      }
      if (chunk.lines.length > 0 || statusChanged) options.onUpdate?.(chunk.run_status)
      if (chunk.complete) {
        stop()
        return
      }
      schedule(chunk.lines.length >= pageSize ? 0 : interval)
    } catch (e) {
      if (stopped) return
      error.value = e instanceof Error ? e.message : String(e)
      if (e instanceof ApiError && (e.status === 401 || e.status === 404)) {
        stop()
        return
      }
      schedule(interval * 2)
    }
  }

  function stop() {
    stopped = true
    polling.value = false
    if (timer !== null) clearTimeout(timer)
    timer = null
  }

  onScopeDispose(stop)
  void poll()

  return { lines, status, error, polling, stop }
}
