import { describe, expect, it } from 'vitest'
import type { LogLine } from '../api/types'
import { formatLogForCopy } from './logCopy'

function line(id: number, stream: LogLine['stream'], text: string, at = '2026-10-20 08:05:09.000'): LogLine {
  return { id, step: 0, stream, line: text, at }
}

const time = (at: string) => at.slice(11, 19)

describe('formatLogForCopy', () => {
  it('prints one line per log line with time, stream marker and text', () => {
    const text = formatLogForCopy(
      [
        line(1, 'system', 'Step 1/1: Build'),
        line(2, 'system', '$ npm ci'),
        line(3, 'stdout', 'added 12 packages'),
        line(4, 'stderr', 'npm warn deprecated'),
      ],
      time,
    )

    expect(text).toBe(
      [
        '08:05:09  Step 1/1: Build',
        '08:05:09  $ npm ci',
        '08:05:09  added 12 packages',
        '08:05:09  [stderr] npm warn deprecated',
      ].join('\n') + '\n',
    )
  })

  it('returns an empty string without lines', () => {
    expect(formatLogForCopy([], time)).toBe('')
  })

  it('uses the app clock format by default', () => {
    expect(formatLogForCopy([line(1, 'stdout', 'hi')])).toMatch(/^\d{2}:\d{2}:\d{2} {2}hi\n$/)
  })
})
