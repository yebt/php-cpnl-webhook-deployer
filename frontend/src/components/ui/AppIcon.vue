<script setup lang="ts">
import type { PropType } from 'vue'
import { iconPaths, type IconName } from './icons'

const props = defineProps({
  name: { type: String as PropType<IconName>, required: true },
  size: { type: Number, default: 16 },
})
</script>

<template>
  <svg
    :width="props.size"
    :height="props.size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    focusable="false"
  >
    <path :d="iconPaths[props.name]" />
  </svg>
</template>
