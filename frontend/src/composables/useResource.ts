import { ref, shallowRef } from 'vue'

/**
 * Loads data with explicit loading/error state. `reload()` keeps the previous data visible
 * while refreshing (no flash of empty content on background refreshes).
 */
export function useResource<T>(loader: () => Promise<T>, options: { immediate?: boolean } = {}) {
  const data = shallowRef<T | null>(null)
  const error = ref<string | null>(null)
  const loading = ref(false)
  let generation = 0

  async function reload(): Promise<void> {
    const current = ++generation
    loading.value = true
    try {
      const value = await loader()
      if (current !== generation) return
      data.value = value
      error.value = null
    } catch (e) {
      if (current !== generation) return
      error.value = e instanceof Error ? e.message : String(e)
    } finally {
      if (current === generation) loading.value = false
    }
  }

  if (options.immediate !== false) void reload()

  return { data, error, loading, reload }
}
