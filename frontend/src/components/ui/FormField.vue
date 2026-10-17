<script setup lang="ts">
/** Label + control + hint/error wiring. The slot receives ids for aria attributes. */
const props = defineProps<{ id: string; label: string; hint?: string; error?: string; optional?: boolean }>()
const hintId = `${props.id}-hint`
const errorId = `${props.id}-error`
</script>

<template>
  <div class="field">
    <label :for="id">
      {{ label }}
      <span v-if="optional" class="faint">(optional)</span>
    </label>
    <slot
      :id="id"
      :invalid="Boolean(error)"
      :described-by="[error ? errorId : '', hint ? hintId : ''].filter(Boolean).join(' ') || undefined"
    />
    <p v-if="error" :id="errorId" class="error">{{ error }}</p>
    <p v-if="hint" :id="hintId" class="hint">{{ hint }}</p>
  </div>
</template>

<style scoped>
/* align-content: start keeps label + control at the top when a grid row stretches the field
   (a neighbour with a hint or error is taller), so inputs in one row stay aligned. */
.field { display: grid; gap: 6px; min-width: 0; align-content: start; }
label { font-weight: 500; font-size: 13px; }
.hint, .error { margin: 0; font-size: 12.5px; }
.hint { color: var(--text-faint); }
.error { color: var(--danger); }
</style>
