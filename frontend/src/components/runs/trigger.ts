import type { RunSummary } from '../../api/types'
import { shortCommit } from '../../lib/format'

/** "webhook · main · a1b2c3d" style summary of what started a run. */
export function triggerSummary(run: RunSummary): string {
  const meta = run.trigger_meta ?? {}
  return [run.trigger, meta.branch ?? meta.ref, shortCommit(meta.commit)].filter(Boolean).join(' · ')
}
