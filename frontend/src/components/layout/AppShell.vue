<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import AppSidebar from './AppSidebar.vue'
import AppTopbar from './AppTopbar.vue'

const route = useRoute()
const navOpen = ref(false)

// The mobile drawer closes on every navigation.
watch(() => route.fullPath, () => (navOpen.value = false))
</script>

<template>
  <div class="shell" :class="{ 'nav-open': navOpen }">
    <a class="skip-link" href="#main">Skip to content</a>
    <AppSidebar class="sidebar" @navigate="navOpen = false" />
    <div class="scrim" aria-hidden="true" @click="navOpen = false" />
    <div class="main-col">
      <AppTopbar :nav-open="navOpen" @toggle-nav="navOpen = !navOpen" />
      <main id="main" tabindex="-1">
        <slot />
      </main>
    </div>
  </div>
</template>

<style scoped>
.shell {
  display: grid;
  grid-template-columns: var(--sidebar-w) minmax(0, 1fr);
  min-height: 100vh;
}
.sidebar {
  position: sticky;
  top: 0;
  height: 100vh;
}
.main-col { min-width: 0; display: flex; flex-direction: column; }
main { flex: 1; outline: none; }
.scrim { display: none; }
.skip-link {
  position: absolute;
  left: -999px;
  top: 8px;
  z-index: 100;
  padding: 8px 12px;
  background: var(--accent);
  color: var(--accent-ink);
  border-radius: var(--radius-sm);
}
.skip-link:focus { left: 8px; }

@media (max-width: 900px) {
  .shell { grid-template-columns: minmax(0, 1fr); }
  .sidebar {
    position: fixed;
    inset: 0 auto 0 0;
    z-index: 40;
    width: min(280px, 85vw);
    transform: translateX(-100%);
    transition: transform 0.18s ease;
  }
  .nav-open .sidebar { transform: none; }
  .nav-open .scrim {
    display: block;
    position: fixed;
    inset: 0;
    z-index: 30;
    background: rgba(0, 0, 0, 0.5);
  }
}
@media (prefers-reduced-motion: reduce) { .sidebar { transition: none; } }
</style>
