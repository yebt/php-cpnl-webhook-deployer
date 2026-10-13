<script setup lang="ts">
import type { RunStep } from '../../api/types'
import { useNow } from '../../composables/useNow'
import { elapsedSince, formatDuration } from '../../lib/format'
import StatusIcon from '../ui/StatusIcon.vue'

defineProps<{ steps: RunStep[]; selected: number | null }>()
defineEmits<{ select: [position: number] }>()

const now = useNow(1000)

function duration(step: RunStep): string {
  if (step.duration_ms !== null) return formatDuration(step.duration_ms)
  if (step.status === 'running') return formatDuration(elapsedSince(step.started_at, now.value))
  return ''
}
</script>

<template>
  <ol class="steps">
    <li v-for="step in steps" :key="step.position">
      <button
        type="button"
        class="step"
        :class="{ selected: selected === step.position, [`is-${step.status}`]: true }"
        :aria-pressed="selected === step.position"
        @click="$emit('select', step.position)"
      >
        <StatusIcon :status="step.status" :size="15" />
        <span class="name">
          {{ step.name }}
          <span v-if="step.continue_on_error && step.status === 'failed'" class="tag">allowed to fail</span>
        </span>
        <span class="duration mono">{{ duration(step) }}</span>
      </button>
    </li>
  </ol>
</template>

<style scoped>
.steps { margin: 0; padding: var(--space-2); list-style: none; display: grid; gap: 2px; }
.step {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  min-height: 36px;
  padding: 6px var(--space-2);
  border: 0;
  border-radius: var(--radius-sm);
  background: transparent;
  color: var(--text);
  font: inherit;
  text-align: left;
  cursor: pointer;
}
.step:hover { background: var(--surface-hover); }
.step.selected { background: var(--surface-2); box-shadow: inset 2px 0 0 var(--accent); }
.step.is-pending .name, .step.is-skipped .name { color: var(--text-faint); }
.name { flex: 1; min-width: 0; overflow-wrap: anywhere; }
.tag {
  margin-left: 4px;
  padding: 0 6px;
  border-radius: 999px;
  background: var(--warning-soft);
  color: var(--warning);
  font-size: 11px;
}
.duration { color: var(--text-faint); font-size: 12px; }
</style>
