<script setup lang="ts">
import { computed } from 'vue'
import { projectsApi, runsApi } from '../api'
import { isActive } from '../api/types'
import RunsTable from '../components/runs/RunsTable.vue'
import AppIcon from '../components/ui/AppIcon.vue'
import EmptyState from '../components/ui/EmptyState.vue'
import InlineAlert from '../components/ui/InlineAlert.vue'
import { useBreadcrumbs } from '../composables/useBreadcrumbs'
import { usePolling } from '../composables/usePolling'
import { useResource } from '../composables/useResource'

useBreadcrumbs(() => [{ label: 'Overview' }])

const runs = useResource(() => runsApi.list({ limit: 25 }))
const projects = useResource(() => projectsApi.list())

const active = computed(() => (runs.data.value ?? []).filter((r) => isActive(r.status)))
const lastFailed = computed(() => {
  const seen = new Set<number>()
  const failing = []
  for (const run of runs.data.value ?? []) {
    if (seen.has(run.project.id)) continue
    seen.add(run.project.id)
    if (run.status === 'failed') failing.push(run)
  }
  return failing
})

// Refresh while something is queued or running.
usePolling(computed(() => active.value.length > 0), () => runs.reload(), 4000)
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>Overview</h1>
        <p class="muted">Latest deployments across all projects.</p>
      </div>
      <RouterLink to="/projects/new" class="btn">
        <AppIcon name="plus" :size="15" />
        New project
      </RouterLink>
    </header>

    <div class="stats" aria-label="Summary">
      <div class="stat card">
        <span class="label">Projects</span>
        <span class="value">{{ projects.data.value?.length ?? '—' }}</span>
      </div>
      <div class="stat card">
        <span class="label">Running or queued</span>
        <span class="value" :class="{ info: active.length > 0 }">{{ runs.data.value ? active.length : '—' }}</span>
      </div>
      <div class="stat card">
        <span class="label">Projects whose last run failed</span>
        <span class="value" :class="{ danger: lastFailed.length > 0 }">{{ runs.data.value ? lastFailed.length : '—' }}</span>
      </div>
    </div>

    <InlineAlert v-if="runs.error.value">{{ runs.error.value }}</InlineAlert>

    <section class="card" aria-labelledby="recent-runs">
      <div class="card-header">
        <h2 id="recent-runs">Recent runs</h2>
        <RouterLink to="/runs" class="muted link">View all</RouterLink>
      </div>
      <RunsTable v-if="runs.data.value?.length" :runs="runs.data.value" />
      <EmptyState
        v-else-if="runs.data.value && projects.data.value?.length === 0"
        title="No projects yet"
        text="Create a project, point it at a folder with a deployer.yml, then deploy it manually or from a webhook."
      >
        <RouterLink to="/projects/new" class="btn btn-primary">Create a project</RouterLink>
      </EmptyState>
      <EmptyState
        v-else-if="runs.data.value"
        title="No runs yet"
        text="Open a project and press Deploy, or call its webhook from your CI."
      >
        <RouterLink to="/projects" class="btn">Go to projects</RouterLink>
      </EmptyState>
      <p v-else-if="runs.loading.value" class="loading muted">Loading…</p>
    </section>
  </div>
</template>

<style scoped>
.page-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: var(--space-3); }
.page-head p { margin: var(--space-1) 0 0; }
.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: var(--space-3); }
.stat { display: grid; gap: var(--space-1); padding: var(--space-4); }
.label { color: var(--text-muted); font-size: 12.5px; }
.value { font-size: 24px; font-weight: 600; font-variant-numeric: tabular-nums; }
.value.info { color: var(--info); }
.value.danger { color: var(--danger); }
.link:hover { color: var(--text); }
.loading { margin: 0; padding: var(--space-4); }
</style>
