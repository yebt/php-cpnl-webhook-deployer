import { computed, ref } from 'vue'
import type { UpdateResult, UpdateStatus } from '../api/types'
import { useResource } from './useResource'

export interface UpdatesClient {
  status: () => Promise<UpdateStatus>
  apply: () => Promise<UpdateResult>
}

export interface UseUpdatesOptions {
  client: UpdatesClient
  /** Blocking confirmation (window.confirm in the app). */
  confirm: (message: string) => boolean
  /** Loads the new version of the UI after a successful update. */
  reloadPage: () => void
  reloadDelayMs?: number
}

/** Update status for the Updates view: check, confirm, apply, then reload into the new version. */
export function useUpdates(options: UseUpdatesOptions) {
  const resource = useResource(() => options.client.status())
  const applying = ref(false)
  const applyError = ref<string | null>(null)
  const result = ref<UpdateResult | null>(null)

  const canUpdate = computed(() => {
    const s = resource.data.value
    return !!s && s.available && s.can_apply && !s.current_is_dev && s.latest !== null
  })

  function confirmationMessage(s: UpdateStatus): string {
    let message = `Update Deployer from ${s.current} to ${s.latest?.version}?`
    if (s.active_runs > 0) {
      const runs = s.active_runs === 1 ? '1 run is' : `${s.active_runs} runs are`
      message += `\n\n${runs} queued or running. They keep running on the current version until they finish; later runs use the new version.`
    }
    return message
  }

  async function apply(): Promise<void> {
    const s = resource.data.value
    if (!s || !canUpdate.value || applying.value) return
    if (!options.confirm(confirmationMessage(s))) return

    applying.value = true
    applyError.value = null
    try {
      result.value = await options.client.apply()
      if (result.value.updated) {
        setTimeout(options.reloadPage, options.reloadDelayMs ?? 2500)
      } else {
        applying.value = false
        await resource.reload()
      }
    } catch (e) {
      applyError.value = e instanceof Error ? e.message : String(e)
      applying.value = false
    }
  }

  return {
    status: resource.data,
    loadError: resource.error,
    loading: resource.loading,
    reload: resource.reload,
    canUpdate,
    applying,
    applyError,
    result,
    apply,
  }
}
