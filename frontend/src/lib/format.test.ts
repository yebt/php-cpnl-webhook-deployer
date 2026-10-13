import { describe, expect, it } from 'vitest'
import { elapsedSince, formatDuration, parseServerTime, timeAgo } from './format'

describe('format helpers', () => {
  it('parses API timestamps as UTC', () => {
    expect(parseServerTime('2026-10-13 10:00:00.250')?.toISOString()).toBe('2026-10-13T10:00:00.250Z')
    expect(parseServerTime(null)).toBeNull()
    expect(parseServerTime('nope')).toBeNull()
  })

  it('formats durations for humans', () => {
    expect(formatDuration(null)).toBe('—')
    expect(formatDuration(420)).toBe('420ms')
    expect(formatDuration(4200)).toBe('4.2s')
    expect(formatDuration(125_000)).toBe('2m 5s')
    expect(formatDuration(3_726_000)).toBe('1h 2m')
  })

  it('describes relative times', () => {
    const now = Date.parse('2026-10-13T10:05:00Z')
    expect(timeAgo('2026-10-13 10:04:58.000', now)).toBe('just now')
    expect(timeAgo('2026-10-13 10:04:00.000', now)).toBe('1m ago')
    expect(timeAgo('2026-10-13 07:05:00.000', now)).toBe('3h ago')
    expect(elapsedSince('2026-10-13 10:04:00.000', now)).toBe(60_000)
  })
})
