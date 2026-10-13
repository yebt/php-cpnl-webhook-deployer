<script setup lang="ts">
defineProps<{ title: string; text?: string }>()
</script>

<template>
  <div class="empty">
    <p class="title">{{ title }}</p>
    <p v-if="text" class="muted">{{ text }}</p>
    <div v-if="$slots.default" class="actions"><slot /></div>
  </div>
</template>

<style scoped>
.empty { padding: var(--space-6) var(--space-4); text-align: center; }
.title { margin: 0 0 var(--space-1); font-weight: 600; }
p { margin: 0; }
.actions { margin-top: var(--space-4); display: flex; justify-content: center; gap: var(--space-2); }
</style>
