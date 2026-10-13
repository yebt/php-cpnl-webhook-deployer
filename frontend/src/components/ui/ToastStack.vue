<script setup lang="ts">
import { useToasts } from '../../composables/useToasts'
import AppIcon from './AppIcon.vue'

const { toasts, dismiss } = useToasts()
</script>

<template>
  <div class="toasts" aria-live="polite">
    <div v-for="toast in toasts" :key="toast.id" class="toast" :class="`is-${toast.kind}`">
      <AppIcon :name="toast.kind === 'error' ? 'alert' : 'check'" :size="15" />
      <span>{{ toast.message }}</span>
      <button type="button" class="btn btn-ghost btn-sm" aria-label="Dismiss" @click="dismiss(toast.id)">
        <AppIcon name="close" :size="14" />
      </button>
    </div>
  </div>
</template>

<style scoped>
.toasts {
  position: fixed;
  right: var(--space-4);
  bottom: var(--space-4);
  z-index: 50;
  display: grid;
  gap: var(--space-2);
  max-width: min(380px, calc(100vw - 32px));
}
.toast {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-2) var(--space-2) var(--space-2) var(--space-3);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius);
  background: var(--surface-2);
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
}
.toast span { flex: 1; }
.is-success svg:first-child { color: var(--success); }
.is-error svg:first-child { color: var(--danger); }
</style>
