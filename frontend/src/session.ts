import { readonly, ref } from 'vue'
import { api, authApi } from './api'
import { ApiError } from './api/client'
import type { SessionInfo, User } from './api/types'

/**
 * App-wide session state. `needsSetup` and `user` are resolved once and then kept in sync
 * by login/setup/logout and by the API client's 401 handler.
 */
const user = ref<User | null>(null)
const needsSetup = ref<boolean | null>(null)
let resolved = false

function accept(info: SessionInfo) {
  user.value = info.user
  needsSetup.value = false
  api.setCsrfToken(info.csrf_token)
  resolved = true
}

async function resolve(): Promise<void> {
  if (resolved) return
  const status = await authApi.setupStatus()
  needsSetup.value = status.needs_setup
  if (!status.needs_setup) {
    try {
      accept(await authApi.me())
    } catch (e) {
      if (!(e instanceof ApiError && e.status === 401)) throw e
      user.value = null
    }
  }
  resolved = true
}

export function useSession() {
  return {
    user: readonly(user),
    needsSetup: readonly(needsSetup),
    resolve,
    async setup(username: string, password: string) {
      accept(await authApi.setup(username, password))
    },
    async login(username: string, password: string) {
      accept(await authApi.login(username, password))
    },
    async logout() {
      try {
        await authApi.logout()
      } finally {
        this.forget()
      }
    },
    /** Drops local session state (expired session, logout). */
    forget() {
      user.value = null
      api.setCsrfToken(null)
    },
  }
}
