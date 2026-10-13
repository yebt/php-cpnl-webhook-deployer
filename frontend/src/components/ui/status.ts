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

export const statusIcon: Record<AnyStatus, IconName> = {
  queued: 'clock',
  pending: 'circle',
  running: 'spinner',
  success: 'check',
  failed: 'x',
  cancelled: 'ban',
  skipped: 'skip',
}
