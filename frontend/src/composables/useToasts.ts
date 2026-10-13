import { readonly, ref } from 'vue'

export interface Toast {
  id: number
  kind: 'success' | 'error' | 'info'
  message: string
}

const toasts = ref<Toast[]>([])
let nextId = 1

/** Short, non-blocking confirmations ("Saved", "Test message sent"). Errors stay inline in forms. */
export function useToasts() {
  function dismiss(id: number) {
    toasts.value = toasts.value.filter((t) => t.id !== id)
  }
  function push(message: string, kind: Toast['kind'] = 'success', ttl = 4000) {
    const id = nextId++
    toasts.value = [...toasts.value, { id, kind, message }]
    setTimeout(() => dismiss(id), ttl)
  }
  return { toasts: readonly(toasts), push, dismiss }
}
