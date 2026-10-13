<script setup lang="ts">
import { ref } from 'vue'
import AppIcon from './AppIcon.vue'

const props = defineProps<{ text: string; label?: string }>()
const copied = ref(false)

/** Clipboard API needs a secure context; fall back to a hidden textarea on plain HTTP. */
async function copy() {
  try {
    await navigator.clipboard.writeText(props.text)
  } catch {
    const area = document.createElement('textarea')
    area.value = props.text
    area.setAttribute('readonly', '')
    area.style.position = 'fixed'
    area.style.opacity = '0'
    document.body.appendChild(area)
    area.select()
    document.execCommand('copy')
    area.remove()
  }
  copied.value = true
  setTimeout(() => (copied.value = false), 1600)
}
</script>

<template>
  <button type="button" class="btn btn-sm" @click="copy">
    <AppIcon :name="copied ? 'check' : 'copy'" :size="14" />
    <span>{{ copied ? 'Copied' : (label ?? 'Copy') }}</span>
    <span class="sr-only" aria-live="polite">{{ copied ? 'Copied to clipboard' : '' }}</span>
  </button>
</template>
