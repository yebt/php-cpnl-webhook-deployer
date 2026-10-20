import { computed, shallowRef } from 'vue'

export interface ConfirmOptions {
  title: string
  /** Consequences, in one or two sentences. Line breaks are kept. */
  message?: string
  confirmLabel?: string
  cancelLabel?: string
  /** Destructive action: red confirm button, and focus starts on Cancel. */
  danger?: boolean
}

export interface ConfirmRequest extends Required<Omit<ConfirmOptions, 'message'>> {
  id: number
  message: string
}

interface Pending {
  request: ConfirmRequest
  resolve: (answer: boolean) => void
}

// One app-wide dialog: ConfirmDialog (mounted once in App.vue) renders `current`.
const current = shallowRef<Pending | null>(null)
const queue: Pending[] = []
let nextId = 1
const currentRequest = computed<ConfirmRequest | null>(() => current.value?.request ?? null)

function showNext() {
  current.value = queue.shift() ?? null
}

function settle(answer: boolean) {
  const pending = current.value
  if (!pending) return
  pending.resolve(answer)
  showNext()
}

/**
 * Promise-based replacement for window.confirm: resolves true when confirmed, false when
 * cancelled (button, Esc, backdrop). Requests made while a dialog is open wait their turn.
 */
export function useConfirm() {
  return function confirm(options: ConfirmOptions): Promise<boolean> {
    return new Promise<boolean>((resolve) => {
      queue.push({
        resolve,
        request: {
          id: nextId++,
          title: options.title,
          message: options.message ?? '',
          confirmLabel: options.confirmLabel ?? 'Confirm',
          cancelLabel: options.cancelLabel ?? 'Cancel',
          danger: options.danger ?? false,
        },
      })
      if (!current.value) showNext()
    })
  }
}

/** For ConfirmDialog only: the request on screen and how to answer it. */
export function useConfirmHost() {
  return {
    current: currentRequest,
    settle,
    /** Answers "no" to everything pending (the host unmounted). */
    cancelAll() {
      while (current.value) settle(false)
    },
  }
}

export function resetConfirmForTests() {
  queue.length = 0
  current.value = null
}
