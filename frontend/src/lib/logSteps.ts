import type { LogLine } from '../api/types'

/**
 * The runner tags every line of a step (its "Step N/M" header, the "$ command" line,
 * stdout/stderr and the result line) with the step's 0-based position; run-level
 * lines (start, finish, notifications) carry null.
 */
export function linesForStep(lines: readonly LogLine[], position: number): LogLine[] {
  return lines.filter((line) => line.step === position)
}

/** Lines grouped by step position, in first-seen order; run-level lines under null. */
export function groupLinesByStep(lines: readonly LogLine[]): Map<number | null, LogLine[]> {
  const groups = new Map<number | null, LogLine[]>()
  for (const line of lines) {
    const group = groups.get(line.step)
    if (group) group.push(line)
    else groups.set(line.step, [line])
  }
  return groups
}

type QueryValue = string | null | undefined | readonly (string | null)[]

/**
 * Reads `?step=N` (1-based, as shown in "Step N/M") into a 0-based position.
 * Anything malformed or beyond `stepCount` (when known) means "no step selected".
 */
export function parseStepQuery(value: QueryValue, stepCount?: number): number | null {
  const raw = Array.isArray(value) ? value[0] : value
  if (typeof raw !== 'string' || !/^\d+$/.test(raw)) return null
  const number = Number(raw)
  if (number < 1 || (stepCount !== undefined && number > stepCount)) return null
  return number - 1
}

export function stepQueryValue(position: number): string {
  return String(position + 1)
}
