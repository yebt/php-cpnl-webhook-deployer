<script setup lang="ts">
import { computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { runsApi } from '../../api'
import type { RunSummary } from '../../api/types'
import { useResource } from '../../composables/useResource'
import { useSession } from '../../session'
import AppIcon from '../ui/AppIcon.vue'
import type { IconName } from '../ui/icons'
import StatusIcon from '../ui/StatusIcon.vue'

defineEmits<{ navigate: [] }>()

const route = useRoute()
const router = useRouter()
const session = useSession()

const nav: { to: string; label: string; icon: IconName; match: RegExp }[] = [
  { to: '/', label: 'Overview', icon: 'overview', match: /^\/$/ },
  { to: '/projects', label: 'Projects', icon: 'projects', match: /^\/projects/ },
  { to: '/runs', label: 'Runs', icon: 'runs', match: /^\/runs/ },
  { to: '/notifiers', label: 'Notifiers', icon: 'notifiers', match: /^\/notifiers/ },
]

// "Recent projects" = the projects of the latest runs, newest first, with their last status.
const recentRuns = useResource(() => runsApi.list({ limit: 40 }))
const recentProjects = computed(() => {
  const seen = new Map<number, RunSummary>()
  for (const run of recentRuns.data.value ?? []) {
    if (!seen.has(run.project.id) && run.project.name) seen.set(run.project.id, run)
    if (seen.size === 5) break
  }
  return [...seen.values()]
})
watch(() => route.path, () => void recentRuns.reload())

async function logout() {
  await session.logout().catch(() => undefined)
  await router.push({ name: 'login' })
}
</script>

<template>
  <aside class="sidebar" aria-label="Main navigation">
    <RouterLink to="/" class="brand" @click="$emit('navigate')">
      <svg width="22" height="22" viewBox="0 0 32 32" aria-hidden="true">
        <path d="M9 21.5 16 9l7 12.5z" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linejoin="round" />
      </svg>
      <span>Deployer</span>
    </RouterLink>

    <nav>
      <RouterLink
        v-for="item in nav"
        :key="item.to"
        :to="item.to"
        class="nav-item"
        :class="{ active: item.match.test(route.path) }"
        :aria-current="item.match.test(route.path) ? 'page' : undefined"
        @click="$emit('navigate')"
      >
        <AppIcon :name="item.icon" />
        {{ item.label }}
      </RouterLink>
    </nav>

    <section v-if="recentProjects.length" class="recent" aria-labelledby="recent-title">
      <h2 id="recent-title" class="section-title">Recent projects</h2>
      <RouterLink
        v-for="run in recentProjects"
        :key="run.project.id"
        :to="`/projects/${run.project.id}`"
        class="nav-item small"
        @click="$emit('navigate')"
      >
        <StatusIcon :status="run.status" :size="13" />
        <span class="truncate">{{ run.project.name }}</span>
      </RouterLink>
    </section>

    <div class="footer">
      <span class="user truncate" :title="session.user.value?.username">{{ session.user.value?.username }}</span>
      <button type="button" class="btn btn-ghost btn-sm" @click="logout">
        <AppIcon name="logout" :size="14" />
        Log out
      </button>
    </div>
  </aside>
</template>

<style scoped>
.sidebar {
  display: flex;
  flex-direction: column;
  gap: var(--space-5);
  padding: var(--space-4) var(--space-3);
  border-right: 1px solid var(--border);
  background: var(--bg-sunken);
  overflow-y: auto;
}
.brand {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: 0 var(--space-2);
  height: 28px;
  font-weight: 650;
  color: var(--accent);
}
.brand span { color: var(--text); }
nav, .recent { display: grid; gap: 2px; }
.section-title {
  margin-bottom: var(--space-1);
  padding: 0 var(--space-2);
  color: var(--text-faint);
  font-size: 11.5px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}
.nav-item {
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 34px;
  padding: 0 var(--space-2);
  border-radius: var(--radius-sm);
  color: var(--text-muted);
  font-weight: 500;
}
.nav-item:hover { background: var(--surface-hover); color: var(--text); }
.nav-item.active { background: var(--surface-2); color: var(--text); box-shadow: inset 2px 0 0 var(--accent); }
.nav-item.small { min-height: 30px; font-weight: 400; font-size: 13px; }
.truncate { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
.footer {
  margin-top: auto;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
  padding: var(--space-3) var(--space-2) 0;
  border-top: 1px solid var(--border);
}
.user { color: var(--text-muted); font-size: 13px; }
</style>
