<script setup lang="ts">
import { useRouter } from 'vue-router'
import type { RunSummary } from '../../api/types'
import { useNow } from '../../composables/useNow'
import { elapsedSince, formatDuration, fullDateTime, timeAgo } from '../../lib/format'
import StatusBadge from '../ui/StatusBadge.vue'
import { triggerSummary } from './trigger'

withDefaults(defineProps<{ runs: RunSummary[]; showProject?: boolean }>(), { showProject: true })

const router = useRouter()
const now = useNow(5000)

function duration(run: RunSummary): string {
  if (run.duration_ms !== null) return formatDuration(run.duration_ms)
  return run.status === 'running' ? formatDuration(elapsedSince(run.started_at, now.value)) : '—'
}
</script>

<template>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th scope="col">Run</th>
          <th scope="col">Status</th>
          <th v-if="showProject" scope="col">Project</th>
          <th scope="col" class="hide-sm">Trigger</th>
          <th scope="col">Started</th>
          <th scope="col" class="hide-sm">Duration</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="run in runs" :key="run.id" class="clickable" @click="router.push(`/runs/${run.id}`)">
          <td>
            <RouterLink :to="`/runs/${run.id}`" class="run-link mono" @click.stop>#{{ run.id }}</RouterLink>
          </td>
          <td><StatusBadge :status="run.status" /></td>
          <td v-if="showProject" class="project">{{ run.project.name ?? `Project ${run.project.id}` }}</td>
          <td class="hide-sm">
            <span class="trigger mono">{{ triggerSummary(run) }}</span>
            <span v-if="run.trigger_meta?.message" class="message faint">{{ run.trigger_meta.message }}</span>
          </td>
          <td class="muted" :title="fullDateTime(run.started_at ?? run.created_at)">
            {{ timeAgo(run.started_at ?? run.created_at, now) }}
          </td>
          <td class="muted mono hide-sm">{{ duration(run) }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.table-wrap { overflow-x: auto; }
.run-link { color: var(--text); font-weight: 500; }
.run-link:hover { color: var(--accent); }
.project { font-weight: 500; }
.trigger { display: block; color: var(--text-muted); }
.message {
  display: block;
  max-width: 340px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 12.5px;
}
td { white-space: nowrap; }
</style>
