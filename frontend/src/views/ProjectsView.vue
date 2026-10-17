<script setup lang="ts">
import { computed, onScopeDispose } from 'vue'
import { useRouter } from 'vue-router'
import { projectsApi, runsApi } from '../api'
import { isActive, type RunSummary } from '../api/types'
import AppIcon from '../components/ui/AppIcon.vue'
import EmptyState from '../components/ui/EmptyState.vue'
import InlineAlert from '../components/ui/InlineAlert.vue'
import StatusBadge from '../components/ui/StatusBadge.vue'
import { onRunFinished } from '../composables/runEvents'
import { useAdaptivePolling } from '../composables/useAdaptivePolling'
import { useBreadcrumbs } from '../composables/useBreadcrumbs'
import { useNow } from '../composables/useNow'
import { useResource } from '../composables/useResource'
import { timeAgo } from '../lib/format'

useBreadcrumbs(() => [{ label: 'Projects' }])

const router = useRouter()
const now = useNow(30_000)
const projects = useResource(() => projectsApi.list())
const runs = useResource(() => runsApi.list({ limit: 200 }), { immediate: false })
// "Last run" stays live like the sidebar: fast while something is queued/running.
const polling = useAdaptivePolling(() => runs.reload(), {
  activeMs: 3000,
  idleMs: 30000,
  isActive: () => (runs.data.value ?? []).some((run) => isActive(run.status)),
})
onScopeDispose(onRunFinished(() => polling.refresh()))

const lastRun = computed(() => {
  const map = new Map<number, RunSummary>()
  for (const run of runs.data.value ?? []) if (!map.has(run.project.id)) map.set(run.project.id, run)
  return map
})
</script>

<template>
  <div class="page">
    <header class="page-head">
      <h1>Projects</h1>
      <RouterLink to="/projects/new" class="btn btn-primary">
        <AppIcon name="plus" :size="15" />
        New project
      </RouterLink>
    </header>

    <InlineAlert v-if="projects.error.value">{{ projects.error.value }}</InlineAlert>

    <section class="card">
      <div v-if="projects.data.value?.length" class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th scope="col">Project</th>
              <th scope="col" class="hide-sm">Folder</th>
              <th scope="col">Last run</th>
              <th scope="col" class="hide-sm">Webhook</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="project in projects.data.value"
              :key="project.id"
              class="clickable"
              @click="router.push(`/projects/${project.id}`)"
            >
              <td>
                <RouterLink :to="`/projects/${project.id}`" class="name" @click.stop>{{ project.name }}</RouterLink>
                <span class="slug mono faint">{{ project.slug }}</span>
              </td>
              <td class="mono muted hide-sm folder">{{ project.folder }}</td>
              <td>
                <template v-if="lastRun.get(project.id)">
                  <StatusBadge :status="lastRun.get(project.id)!.status" />
                  <span class="faint when">{{ timeAgo(lastRun.get(project.id)!.created_at, now) }}</span>
                </template>
                <span v-else class="faint">Never deployed</span>
              </td>
              <td class="hide-sm">
                <span :class="project.has_webhook_token ? 'ok' : 'faint'">{{ project.has_webhook_token ? 'Enabled' : 'No token' }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <EmptyState
        v-else-if="projects.data.value"
        title="No projects yet"
        text="A project is a folder on this server plus a deployer.yml describing the steps to run."
      >
        <RouterLink to="/projects/new" class="btn btn-primary">Create a project</RouterLink>
      </EmptyState>
      <p v-else-if="projects.loading.value" class="loading muted">Loading…</p>
    </section>
  </div>
</template>

<style scoped>
.page-head { display: flex; align-items: center; justify-content: space-between; gap: var(--space-3); }
.table-wrap { overflow-x: auto; }
.name { display: block; font-weight: 600; }
.name:hover { color: var(--accent); }
.slug { font-size: 12px; }
.folder { max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.when { margin-left: var(--space-2); font-size: 12.5px; }
.ok { color: var(--success); }
.loading { margin: 0; padding: var(--space-4); }
</style>
