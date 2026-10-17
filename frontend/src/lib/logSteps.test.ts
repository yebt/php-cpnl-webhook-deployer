import { describe, expect, it } from 'vitest'
import type { LogLine } from '../api/types'
import { groupLinesByStep, linesForStep, parseStepQuery, stepQueryValue } from './logSteps'

let nextId = 1
function line(step: number | null, text: string, stream: LogLine['stream'] = 'stdout'): LogLine {
  return { id: nextId++, step, stream, line: text, at: '2026-10-17 10:00:00' }
}

const log: LogLine[] = [
  line(null, 'Run #3 started for project "shop" (trigger: manual).', 'system'),
  line(0, 'Step 1/2: Install', 'system'),
  line(0, '$ npm ci', 'system'),
  line(0, 'added 12 packages'),
  line(0, 'npm WARN deprecated', 'stderr'),
  line(0, 'Step succeeded (exit code 0, 1.20s).', 'system'),
  line(1, 'Step 2/2: Build', 'system'),
  line(1, '$ npm run build', 'system'),
  line(1, 'built'),
  line(null, 'Run finished: success', 'system'),
]

describe('linesForStep', () => {
  it('keeps only the header, command, output and result of one step, in order', () => {
    expect(linesForStep(log, 0).map((l) => l.line)).toEqual([
      'Step 1/2: Install',
      '$ npm ci',
      'added 12 packages',
      'npm WARN deprecated',
      'Step succeeded (exit code 0, 1.20s).',
    ])
  })

  it('keeps every command line of a multi-command step next to its output', () => {
    const multi = [
      line(0, 'Step 1/1: Deploy', 'system'),
      line(0, '$ git pull', 'system'),
      line(0, 'Already up to date.'),
      line(0, '$ composer install', 'system'),
      line(0, 'Nothing to install', 'stderr'),
      line(0, 'Step succeeded (exit code 0, 2.00s).', 'system'),
    ]
    expect(linesForStep(multi, 0).map((l) => l.line)).toEqual(multi.map((l) => l.line))
  })

  it('returns an empty list for a step without output yet', () => {
    expect(linesForStep(log, 5)).toEqual([])
  })

  it('picks up lines appended later (live polling)', () => {
    const more = [...log, line(1, 'Step succeeded (exit code 0, 0.40s).', 'system')]
    expect(linesForStep(more, 1).map((l) => l.line)).toEqual([
      'Step 2/2: Build',
      '$ npm run build',
      'built',
      'Step succeeded (exit code 0, 0.40s).',
    ])
  })
})

describe('groupLinesByStep', () => {
  it('groups step lines by position and run-level lines under null', () => {
    const groups = groupLinesByStep(log)
    expect([...groups.keys()]).toEqual([null, 0, 1])
    expect(groups.get(0)).toHaveLength(5)
    expect(groups.get(1)).toHaveLength(3)
    expect(groups.get(null)?.map((l) => l.line)).toEqual([
      'Run #3 started for project "shop" (trigger: manual).',
      'Run finished: success',
    ])
  })
})

describe('step query (?step=N, 1-based like "Step N/M")', () => {
  it('maps the query to a 0-based position', () => {
    expect(parseStepQuery('1')).toBe(0)
    expect(parseStepQuery('3', 3)).toBe(2)
    expect(parseStepQuery(['2'])).toBe(1)
  })

  it('ignores missing, malformed or out-of-range values', () => {
    expect(parseStepQuery(undefined)).toBeNull()
    expect(parseStepQuery(null)).toBeNull()
    expect(parseStepQuery('0')).toBeNull()
    expect(parseStepQuery('-1')).toBeNull()
    expect(parseStepQuery('abc')).toBeNull()
    expect(parseStepQuery('1.5')).toBeNull()
    expect(parseStepQuery('4', 3)).toBeNull()
  })

  it('round-trips with stepQueryValue', () => {
    expect(stepQueryValue(0)).toBe('1')
    expect(parseStepQuery(stepQueryValue(7))).toBe(7)
  })
})
