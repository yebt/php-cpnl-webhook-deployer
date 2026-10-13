<script setup lang="ts">
import type { PropType } from 'vue'
import AppIcon from './AppIcon.vue'

defineProps({
  kind: { type: String as PropType<'error' | 'warning' | 'info'>, default: 'error' },
  title: { type: String, default: '' },
})
</script>

<template>
  <div class="alert" :class="`is-${kind}`" :role="kind === 'error' ? 'alert' : 'status'">
    <AppIcon :name="kind === 'info' ? 'circle' : 'alert'" :size="16" class="icon" />
    <div class="content">
      <strong v-if="title">{{ title }}</strong>
      <div><slot /></div>
    </div>
    <div v-if="$slots.actions" class="actions"><slot name="actions" /></div>
  </div>
</template>

<style scoped>
.alert {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  border: 1px solid;
  border-radius: var(--radius-sm);
}
.icon { flex: none; margin-top: 2px; }
.content { flex: 1; min-width: 0; overflow-wrap: anywhere; }
.actions { display: flex; gap: var(--space-2); flex-wrap: wrap; }
.is-error { border-color: color-mix(in srgb, var(--danger) 45%, transparent); background: var(--danger-soft); }
.is-error .icon { color: var(--danger); }
.is-warning { border-color: color-mix(in srgb, var(--warning) 45%, transparent); background: var(--warning-soft); }
.is-warning .icon { color: var(--warning); }
.is-info { border-color: color-mix(in srgb, var(--info) 40%, transparent); background: var(--info-soft); }
.is-info .icon { color: var(--info); }
@media (max-width: 560px) { .alert { flex-wrap: wrap; } .actions { width: 100%; } }
</style>
