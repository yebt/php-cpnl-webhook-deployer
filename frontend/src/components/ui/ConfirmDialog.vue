<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useConfirmHost } from '../../composables/useConfirm'
import AppIcon from './AppIcon.vue'

/*
 * The single app-wide confirmation dialog (mounted once in App.vue), driven by useConfirm().
 * A native modal <dialog> keeps the page behind it inert; Tab is additionally kept inside.
 * Keys: Esc cancels; Enter confirms unless focus is on Cancel. Danger dialogs open with
 * focus on Cancel, so a stray Enter never deletes anything.
 */
const host = useConfirmHost()
const dialog = ref<HTMLDialogElement | null>(null)
const cancelButton = ref<HTMLButtonElement | null>(null)
const confirmButton = ref<HTMLButtonElement | null>(null)
let returnFocus: HTMLElement | null = null

watch(
  () => host.current.value,
  async (request, previous) => {
    const el = dialog.value
    if (!el) return
    if (request && !previous) {
      returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null
    }
    if (request) {
      await nextTick()
      if (!el.open) el.showModal()
      ;(request.danger ? cancelButton.value : confirmButton.value)?.focus()
    } else {
      if (el.open) el.close()
      const target = returnFocus
      returnFocus = null
      if (target?.isConnected) target.focus()
    }
  },
  { flush: 'post' },
)

function answer(value: boolean) {
  host.settle(value)
}

/** Native Esc handling: keep the dialog under our control instead of letting it close itself. */
function onCancel(event: Event) {
  event.preventDefault()
  answer(false)
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Enter' && !event.isComposing) {
    event.preventDefault()
    answer(event.target !== cancelButton.value)
    return
  }
  if (event.key !== 'Tab') return
  const focusables = [cancelButton.value, confirmButton.value].filter((b): b is HTMLButtonElement => b !== null)
  const first = focusables[0]
  const last = focusables[focusables.length - 1]
  if (!first || !last) return
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}

/** A click on the backdrop lands on the <dialog> itself (the panel fills its content box). */
function onClick(event: MouseEvent) {
  if (event.target === dialog.value) answer(false)
}

onBeforeUnmount(() => host.cancelAll())
</script>

<template>
  <dialog
    ref="dialog"
    class="confirm"
    role="alertdialog"
    aria-modal="true"
    aria-labelledby="confirm-title"
    :aria-describedby="host.current.value?.message ? 'confirm-message' : undefined"
    @cancel="onCancel"
    @keydown="onKeydown"
    @click="onClick"
  >
    <div v-if="host.current.value" class="panel" :class="{ danger: host.current.value.danger }">
      <div class="head">
        <span v-if="host.current.value.danger" class="badge" aria-hidden="true"><AppIcon name="alert" :size="16" /></span>
        <h2 id="confirm-title">{{ host.current.value.title }}</h2>
      </div>
      <p v-if="host.current.value.message" id="confirm-message" class="message">{{ host.current.value.message }}</p>
      <div class="actions">
        <button ref="cancelButton" type="button" class="btn" @click="answer(false)">
          {{ host.current.value.cancelLabel }}
        </button>
        <button
          ref="confirmButton"
          type="button"
          class="btn"
          :class="host.current.value.danger ? 'btn-danger-solid' : 'btn-primary'"
          @click="answer(true)"
        >
          {{ host.current.value.confirmLabel }}
        </button>
      </div>
    </div>
  </dialog>
</template>

<style scoped>
.confirm {
  width: min(440px, calc(100vw - 32px));
  max-width: none;
  padding: 0;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius);
  background: var(--surface);
  color: var(--text);
  box-shadow: 0 24px 64px rgba(0, 0, 0, 0.45);
}
.confirm::backdrop {
  background: rgba(0, 0, 0, 0.55);
}
.confirm[open] {
  animation: enter 0.14s ease-out;
}
.panel {
  display: grid;
  gap: var(--space-3);
  padding: var(--space-5);
}
.head {
  display: flex;
  align-items: center;
  gap: var(--space-3);
}
.head h2 {
  font-size: 16px;
  overflow-wrap: anywhere;
}
.badge {
  display: inline-grid;
  place-items: center;
  flex: none;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: var(--danger-soft);
  color: var(--danger);
}
.message {
  margin: 0;
  color: var(--text-muted);
  white-space: pre-line;
  overflow-wrap: anywhere;
}
.actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: var(--space-2);
  margin-top: var(--space-2);
}
.btn-danger-solid {
  background: var(--danger-solid);
  border-color: var(--danger-solid);
  color: #fff;
}
.btn-danger-solid:hover:not(:disabled) {
  background: color-mix(in srgb, var(--danger-solid) 85%, #000);
  border-color: color-mix(in srgb, var(--danger-solid) 85%, #000);
}
@keyframes enter {
  from { opacity: 0; transform: translateY(6px) scale(0.98); }
}
@media (prefers-reduced-motion: reduce) {
  .confirm[open] { animation: none; }
}
@media (max-width: 480px) {
  .actions { flex-direction: column-reverse; }
  .actions .btn { width: 100%; }
}
</style>
