<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue'
import { copyText } from '../../lib/clipboard'
import AppIcon from './AppIcon.vue'

const props = defineProps<{
  /** The text, or a function producing it at click time (large or changing content). */
  text: string | (() => string)
  label?: string
  /** Accessible name when the visible label alone is ambiguous ("Copy" on a log). */
  ariaLabel?: string
  disabled?: boolean
}>()
const state = ref<'idle' | 'copied' | 'failed'>('idle')
let timer: ReturnType<typeof setTimeout> | undefined

async function copy() {
  const ok = await copyText(typeof props.text === 'function' ? props.text() : props.text)
  state.value = ok ? 'copied' : 'failed'
  clearTimeout(timer)
  timer = setTimeout(() => (state.value = 'idle'), 1600)
}
onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <button type="button" class="btn btn-sm" :disabled="disabled" :aria-label="ariaLabel" @click="copy">
    <AppIcon :name="state === 'copied' ? 'check' : state === 'failed' ? 'alert' : 'copy'" :size="14" />
    <span>{{ state === 'copied' ? 'Copied' : state === 'failed' ? 'Copy failed' : (label ?? 'Copy') }}</span>
    <span class="sr-only" aria-live="polite">{{
      state === 'copied' ? 'Copied to clipboard' : state === 'failed' ? 'Could not copy; select the text manually' : ''
    }}</span>
  </button>
</template>
