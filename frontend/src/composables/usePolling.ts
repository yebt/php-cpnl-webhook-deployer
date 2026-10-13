import { onScopeDispose, watch, type Ref } from 'vue'

/** Calls `tick` every `intervalMs` while `active` is true; stops with the component. */
export function usePolling(active: Ref<boolean>, tick: () => unknown, intervalMs = 4000) {
  let timer: ReturnType<typeof setInterval> | null = null
  const stop = () => {
    if (timer !== null) clearInterval(timer)
    timer = null
  }
  watch(
    active,
    (on) => {
      stop()
      if (on) timer = setInterval(() => void tick(), intervalMs)
    },
    { immediate: true },
  )
  onScopeDispose(stop)
}
