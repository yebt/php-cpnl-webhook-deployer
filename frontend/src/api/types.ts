/** Shapes returned by the PHP JSON API (backend controllers under src/<Feature>/Infrastructure/Http). */

export type RunStatus = 'queued' | 'running' | 'success' | 'failed' | 'cancelled'
export type StepStatus = 'pending' | 'running' | 'success' | 'failed' | 'cancelled' | 'skipped'
export type LogStreamName = 'stdout' | 'stderr' | 'system'

export const TERMINAL_STATUSES: readonly RunStatus[] = ['success', 'failed', 'cancelled']

export function isActive(status: RunStatus): boolean {
  return status === 'queued' || status === 'running'
}

export interface User {
  id: number
  username: string
}

export interface SessionInfo {
  user: User
  csrf_token: string
  /** Present on GET /api/me (not on login/setup responses). */
  app?: { version: string }
}

export interface ReleaseInfo {
  version: string
  tag: string
  name: string
  /** GitHub release page. */
  url: string
  published_at: string | null
  notes_excerpt: string
}

export interface UpdateStatus {
  current: string
  /** Unstamped build: updates apply only from the CLI with --force. */
  current_is_dev: boolean
  available: boolean
  /** False when running from source instead of the packaged phar. */
  can_apply: boolean
  active_runs: number
  latest: ReleaseInfo | null
}

export interface UpdateResult {
  updated: boolean
  from: string
  to: string
  backup_file: string | null
}

export interface Notifier {
  id: number
  name: string
  type: 'telegram'
  chat_id: string
  /** Telegram forum topic id; null posts to the chat's general thread. */
  thread_id: number | null
  bot_token_masked: string
}

export interface NotifierInput {
  name: string
  type: 'telegram'
  chat_id: string
  /** Positive integer (digits as a string are accepted); null or empty clears it. */
  thread_id?: number | string | null
  /** Empty on update keeps the stored token. */
  bot_token?: string
}

export interface Project {
  id: number
  slug: string
  name: string
  folder: string
  instructions_file: string
  instructions_path: string
  notifier_id: number | null
  has_webhook_token: boolean
  /** When the current token was generated (UTC); null without a token or for tokens predating 0.4.0. */
  token_created_at: string | null
  webhook_path: string
}

export interface ProjectInput {
  name: string
  slug: string
  folder: string
  instructions_file: string
  notifier_id: number | null
}

export interface TriggerMeta {
  event?: string
  ref?: string
  branch?: string
  commit?: string
  message?: string
  author?: string
}

export interface RunSummary {
  id: number
  project: { id: number; slug: string | null; name: string | null }
  status: RunStatus
  trigger: 'webhook' | 'manual' | 'cli' | string
  trigger_meta: TriggerMeta | null
  cancel_requested: boolean
  error: string | null
  created_at: string
  started_at: string | null
  finished_at: string | null
  duration_ms: number | null
}

export interface RunStep {
  position: number
  name: string
  command: string
  status: StepStatus
  exit_code: number | null
  timeout: number
  continue_on_error: boolean
  started_at: string | null
  finished_at: string | null
  duration_ms: number | null
}

export interface RunDetail extends RunSummary {
  steps: RunStep[]
  pipeline_yaml: string | null
}

export interface LogLine {
  id: number
  step: number | null
  stream: LogStreamName
  line: string
  at: string
}

export interface LogChunk {
  lines: LogLine[]
  next_after: number
  run_status: RunStatus
  complete: boolean
}

export interface Instructions {
  path: string
  exists: boolean
  content: string
  valid: boolean
  errors: string[]
}

export interface InstructionsSaved {
  saved: true
  path: string
  valid: boolean
  errors: string[]
}

export interface IssuedToken {
  token: string
  webhook_path: string
  header: string
}
