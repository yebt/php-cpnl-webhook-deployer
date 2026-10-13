<script setup lang="ts">
import { useCurrentBreadcrumbs } from '../../composables/useBreadcrumbs'
import AppIcon from '../ui/AppIcon.vue'

defineProps<{ navOpen: boolean }>()
defineEmits<{ toggleNav: [] }>()

const crumbs = useCurrentBreadcrumbs()
</script>

<template>
  <header class="topbar">
    <button
      type="button"
      class="btn btn-ghost menu"
      :aria-expanded="navOpen"
      aria-label="Toggle navigation"
      @click="$emit('toggleNav')"
    >
      <AppIcon :name="navOpen ? 'close' : 'menu'" :size="18" />
    </button>
    <nav aria-label="Breadcrumb" class="crumbs">
      <ol>
        <li v-for="(crumb, index) in crumbs" :key="index">
          <AppIcon v-if="index > 0" name="chevron" :size="14" class="sep" />
          <RouterLink v-if="crumb.to && index < crumbs.length - 1" :to="crumb.to">{{ crumb.label }}</RouterLink>
          <span v-else :aria-current="index === crumbs.length - 1 ? 'page' : undefined">{{ crumb.label }}</span>
        </li>
      </ol>
    </nav>
  </header>
</template>

<style scoped>
.topbar {
  position: sticky;
  top: 0;
  z-index: 20;
  display: flex;
  align-items: center;
  gap: var(--space-2);
  height: var(--topbar-h);
  padding: 0 var(--space-5);
  border-bottom: 1px solid var(--border);
  background: color-mix(in srgb, var(--bg) 88%, transparent);
  backdrop-filter: blur(8px);
}
.menu { display: none; margin-left: calc(-1 * var(--space-2)); }
.crumbs { min-width: 0; }
ol { display: flex; align-items: center; margin: 0; padding: 0; list-style: none; min-width: 0; }
li { display: flex; align-items: center; min-width: 0; color: var(--text-muted); white-space: nowrap; }
li:last-child { color: var(--text); font-weight: 500; overflow: hidden; }
li:last-child span { overflow: hidden; text-overflow: ellipsis; }
li a:hover { color: var(--text); }
.sep { margin: 0 6px; color: var(--text-faint); flex: none; }
@media (max-width: 900px) {
  .topbar { padding: 0 var(--space-4); }
  .menu { display: inline-flex; }
  li:not(:last-child):not(:nth-last-child(2)) { display: none; }
  li:nth-last-child(2) .sep { display: none; }
}
</style>
