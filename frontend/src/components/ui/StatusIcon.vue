<script setup lang="ts">
import type { PropType } from 'vue'
import type { RunStatus, StepStatus } from '../../api/types'
import AppIcon from './AppIcon.vue'
import { statusIcon, statusLabel } from './status'

defineProps({
  status: { type: String as PropType<RunStatus | StepStatus>, required: true },
  size: { type: Number, default: 16 },
})
</script>

<template>
  <span class="status-icon" :class="`is-${status}`" :title="statusLabel[status]">
    <AppIcon :name="statusIcon[status]" :size="size" :class="{ spin: status === 'running' }" />
    <span class="sr-only">{{ statusLabel[status] }}</span>
  </span>
</template>

<style scoped>
.status-icon { display: inline-flex; color: var(--neutral); flex: none; }
.is-success { color: var(--success); }
.is-failed { color: var(--danger); }
.is-running { color: var(--info); }
.is-queued { color: var(--warning); }
.is-pending, .is-skipped { color: var(--text-faint); }
.spin { animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .spin { animation: none; } }
</style>
