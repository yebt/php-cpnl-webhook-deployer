<script setup lang="ts">
import { computed } from 'vue'

/**
 * The one activity indicator: a faint full track plus a rotating arc.
 * Drawn as a single circle with pathLength-based dashes (no path seams) and a stroke
 * kept at a visible ~1.75-2 device px at every size, so small spinners read as a
 * continuous ring instead of breaking up while they rotate.
 */
const props = withDefaults(defineProps<{ size?: number }>(), { size: 16 })

const VIEWBOX = 16
const strokeWidth = computed(() => ((props.size <= 14 ? 1.75 : 2) * VIEWBOX) / props.size)
const radius = computed(() => (VIEWBOX - strokeWidth.value) / 2 - 0.25)
</script>

<template>
  <svg
    class="spinner"
    :width="size"
    :height="size"
    :viewBox="`0 0 ${VIEWBOX} ${VIEWBOX}`"
    fill="none"
    aria-hidden="true"
    focusable="false"
  >
    <circle cx="8" cy="8" :r="radius" stroke="currentColor" :stroke-width="strokeWidth" opacity="0.25" />
    <circle
      cx="8"
      cy="8"
      :r="radius"
      stroke="currentColor"
      :stroke-width="strokeWidth"
      stroke-linecap="round"
      pathLength="100"
      stroke-dasharray="30 100"
    />
  </svg>
</template>

<style scoped>
.spinner {
  display: block;
  flex: none;
  animation: spinner-rotate 0.9s linear infinite;
  transform-origin: 50% 50%;
  will-change: transform;
}
@keyframes spinner-rotate { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .spinner { animation-duration: 2.4s; } }
</style>
