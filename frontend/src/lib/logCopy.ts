import type { LogLine } from '../api/types'
import { clockTime } from './format'

/**
 * Plain-text version of log lines for the clipboard: `HH:MM:SS  text`, stderr lines
 * marked `[stderr] `; executed commands keep their `$ ` prefix as logged by the runner.
 */
export function formatLogForCopy(lines: readonly LogLine[], time: (at: string) => string = clockTime): string {
  return lines
    .map((line) => `${time(line.at)}  ${line.stream === 'stderr' ? '[stderr] ' : ''}${line.line}\n`)
    .join('')
}
