<script setup lang="ts">
import type { PropType } from 'vue'
import type { RunStatus, StepStatus } from '../../api/types'
import AppIcon from './AppIcon.vue'
import { statusIcon, statusLabel } from './status'

defineProps({
  status: { type: String as PropType<RunStatus | StepStatus>, required: true },
})
</script>

<template>
  <span class="badge" :class="`is-${status}`">
    <AppIcon :name="statusIcon[status]" :size="12" :class="{ spin: status === 'running' }" />
    {{ statusLabel[status] }}
  </span>
</template>

<style scoped>
.badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 2px 8px;
  border: 1px solid currentColor;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 500;
  line-height: 18px;
  white-space: nowrap;
  color: var(--neutral);
  background: color-mix(in srgb, currentColor 10%, transparent);
  border-color: color-mix(in srgb, currentColor 35%, transparent);
}
.is-success { color: var(--success); }
.is-failed { color: var(--danger); }
.is-running { color: var(--info); }
.is-queued { color: var(--warning); }
.spin { animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .spin { animation: none; } }
</style>
