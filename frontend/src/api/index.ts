import { appBasePath } from '../lib/base'
import { createApiClient } from './client'
import type {
  Instructions,
  InstructionsSaved,
  IssuedToken,
  LogChunk,
  Notifier,
  NotifierInput,
  Project,
  ProjectInput,
  RunDetail,
  RunStatus,
  RunSummary,
  SessionInfo,
  UpdateResult,
  UpdateStatus,
} from './types'

let unauthenticatedHandler: () => void = () => undefined

export const api = createApiClient({
  basePath: appBasePath(),
  onUnauthenticated: () => unauthenticatedHandler(),
})

export function onUnauthenticated(handler: () => void) {
  unauthenticatedHandler = handler
}

export const authApi = {
  setupStatus: () => api.get<{ needs_setup: boolean }>('/api/setup/status'),
  setup: (username: string, password: string) =>
    api.post<SessionInfo>('/api/setup', { username, password }, { csrf: false }),
  login: (username: string, password: string) =>
    api.post<SessionInfo>('/api/login', { username, password }, { csrf: false }),
  logout: () => api.post<void>('/api/logout'),
  me: () => api.get<SessionInfo>('/api/me'),
}

export const projectsApi = {
  list: async () => (await api.get<{ projects: Project[] }>('/api/projects')).projects,
  get: async (id: number) => (await api.get<{ project: Project }>(`/api/projects/${id}`)).project,
  create: async (input: ProjectInput) => (await api.post<{ project: Project }>('/api/projects', input)).project,
  update: async (id: number, input: Partial<ProjectInput>) =>
    (await api.put<{ project: Project }>(`/api/projects/${id}`, input)).project,
  remove: (id: number) => api.del(`/api/projects/${id}`),
  regenerateToken: (id: number) => api.post<IssuedToken>(`/api/projects/${id}/token`),
  revokeToken: (id: number) => api.del(`/api/projects/${id}/token`),
  deploy: (id: number) => api.post<{ run_id: number; status: RunStatus }>(`/api/projects/${id}/runs`),
  instructions: (id: number) => api.get<Instructions>(`/api/projects/${id}/instructions`),
  saveInstructions: (id: number, content: string, force = false) =>
    api.put<InstructionsSaved>(`/api/projects/${id}/instructions`, { content }, { query: { force: force ? 1 : undefined } }),
}

export interface RunQuery {
  project?: number
  status?: RunStatus
  limit?: number
  before?: number
}

export const runsApi = {
  list: async (query: RunQuery = {}) => (await api.get<{ runs: RunSummary[] }>('/api/runs', { query: { ...query } })).runs,
  get: async (id: number) => (await api.get<{ run: RunDetail }>(`/api/runs/${id}`)).run,
  logs: (id: number, after: number, limit: number) =>
    api.get<LogChunk>(`/api/runs/${id}/logs`, { query: { after, limit } }),
  cancel: (id: number) => api.post<{ result: 'cancelled' | 'requested' }>(`/api/runs/${id}/cancel`),
  retry: (id: number) => api.post<{ run_id: number; status: RunStatus; retry_of: number }>(`/api/runs/${id}/retry`),
}

export const notifiersApi = {
  list: async () => (await api.get<{ notifiers: Notifier[] }>('/api/notifiers')).notifiers,
  get: async (id: number) => (await api.get<{ notifier: Notifier }>(`/api/notifiers/${id}`)).notifier,
  create: async (input: NotifierInput) => (await api.post<{ notifier: Notifier }>('/api/notifiers', input)).notifier,
  update: async (id: number, input: Partial<NotifierInput>) =>
    (await api.put<{ notifier: Notifier }>(`/api/notifiers/${id}`, input)).notifier,
  remove: (id: number) => api.del(`/api/notifiers/${id}`),
  test: (id: number) => api.post<{ sent: true }>(`/api/notifiers/${id}/test`),
}

export const updatesApi = {
  status: () => api.get<UpdateStatus>('/api/updates'),
  apply: () => api.post<UpdateResult>('/api/updates/apply'),
}
