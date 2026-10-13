/** The API stores UTC timestamps as `Y-m-d H:i:s.v` without a zone suffix. */
export function parseServerTime(value: string | null | undefined): Date | null {
  if (!value) return null
  const date = new Date(`${value.replace(' ', 'T')}Z`)
  return Number.isNaN(date.getTime()) ? null : date
}

export function timeAgo(value: string | null | undefined, now: number = Date.now()): string {
  const date = parseServerTime(value)
  if (!date) return '—'
  const seconds = Math.max(0, Math.round((now - date.getTime()) / 1000))
  if (seconds < 10) return 'just now'
  if (seconds < 60) return `${seconds}s ago`
  const minutes = Math.floor(seconds / 60)
  if (minutes < 60) return `${minutes}m ago`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours}h ago`
  const days = Math.floor(hours / 24)
  if (days < 30) return `${days}d ago`
  return date.toLocaleDateString()
}

export function formatDuration(ms: number | null | undefined): string {
  if (ms === null || ms === undefined || ms < 0) return '—'
  if (ms < 1000) return `${ms}ms`
  const totalSeconds = Math.floor(ms / 1000)
  if (totalSeconds < 60) return `${(ms / 1000).toFixed(1)}s`
  const minutes = Math.floor(totalSeconds / 60)
  const seconds = totalSeconds % 60
  if (minutes < 60) return `${minutes}m ${seconds}s`
  return `${Math.floor(minutes / 60)}h ${minutes % 60}m`
}

/** Duration of something still running, measured from its start to `now`. */
export function elapsedSince(start: string | null | undefined, now: number = Date.now()): number | null {
  const date = parseServerTime(start)
  return date ? Math.max(0, now - date.getTime()) : null
}

export function clockTime(value: string): string {
  const date = parseServerTime(value)
  if (!date) return ''
  return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false })
}

export function fullDateTime(value: string | null | undefined): string {
  return parseServerTime(value)?.toLocaleString() ?? ''
}

export function shortCommit(commit: string | undefined): string {
  return commit ? commit.slice(0, 7) : ''
}
