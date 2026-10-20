<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import AppShell from './components/layout/AppShell.vue'
import ConfirmDialog from './components/ui/ConfirmDialog.vue'
import ToastStack from './components/ui/ToastStack.vue'

const route = useRoute()
// Nothing renders until the first navigation resolves (session check), so the shell never
// flashes on the login screen.
const ready = computed(() => route.matched.length > 0)
const bare = computed(() => route.meta.bare === true)
</script>

<template>
  <!-- Views are keyed by path so /runs/1 → /runs/2 remounts (fresh polling state). -->
  <RouterView v-if="ready && bare" />
  <AppShell v-else-if="ready">
    <RouterView v-slot="{ Component, route: current }">
      <component :is="Component" :key="current.path" />
    </RouterView>
  </AppShell>
  <ToastStack />
  <ConfirmDialog />
</template>
