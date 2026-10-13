import { onScopeDispose, ref } from 'vue'

/** A reactive clock for live durations and "x ago" labels. */
export function useNow(intervalMs = 1000) {
  const now = ref(Date.now())
  const timer = setInterval(() => (now.value = Date.now()), intervalMs)
  onScopeDispose(() => clearInterval(timer))
  return now
}
