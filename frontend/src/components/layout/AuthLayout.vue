<script setup lang="ts">
defineProps<{ title: string; subtitle?: string }>()
</script>

<template>
  <main class="auth">
    <div class="panel">
      <div class="brand">
        <svg width="26" height="26" viewBox="0 0 32 32" aria-hidden="true">
          <path d="M9 21.5 16 9l7 12.5z" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linejoin="round" />
        </svg>
        Deployer
      </div>
      <h1>{{ title }}</h1>
      <p v-if="subtitle" class="muted subtitle">{{ subtitle }}</p>
      <slot />
    </div>
  </main>
</template>

<style scoped>
.auth {
  display: grid;
  place-items: center;
  min-height: 100vh;
  padding: var(--space-4);
}
.panel {
  width: 100%;
  max-width: 380px;
  padding: var(--space-6) var(--space-5);
  border: 1px solid var(--border);
  border-radius: 12px;
  background: var(--surface);
}
.brand {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin-bottom: var(--space-5);
  color: var(--accent);
  font-weight: 650;
}
h1 { font-size: 18px; }
.subtitle { margin: var(--space-1) 0 0; }
:slotted(form) { margin-top: var(--space-5); }
</style>
