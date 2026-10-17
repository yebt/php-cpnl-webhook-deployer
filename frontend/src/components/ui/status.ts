import type { RunStatus, StepStatus } from '../../api/types'
import type { IconName } from './icons'

type AnyStatus = RunStatus | StepStatus

export const statusLabel: Record<AnyStatus, string> = {
  queued: 'Queued',
  pending: 'Pending',
  running: 'Running',
  success: 'Success',
  failed: 'Failed',
  cancelled: 'Cancelled',
  skipped: 'Skipped',
}

/** Static icon per status; `running` renders AppSpinner instead (its entry is only a fallback). */
export const statusIcon: Record<AnyStatus, IconName> = {
  queued: 'clock',
  pending: 'circle',
  running: 'circle',
  success: 'check',
  failed: 'x',
  cancelled: 'ban',
  skipped: 'skip',
}
