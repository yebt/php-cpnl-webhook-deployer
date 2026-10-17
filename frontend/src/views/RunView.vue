<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { runsApi } from '../api'
import { isActive } from '../api/types'
import LogView from '../components/runs/LogView.vue'
import StepList from '../components/runs/StepList.vue'
import { triggerSummary } from '../components/runs/trigger'
import AppIcon from '../components/ui/AppIcon.vue'
import InlineAlert from '../components/ui/InlineAlert.vue'
import StatusBadge from '../components/ui/StatusBadge.vue'
import StatusIcon from '../components/ui/StatusIcon.vue'
import { useBreadcrumbs } from '../composables/useBreadcrumbs'
import { useLogStream } from '../composables/useLogStream'
import { useNow } from '../composables/useNow'
import { useResource } from '../composables/useResource'
import { useToasts } from '../composables/useToasts'
import { elapsedSince, formatDuration, fullDateTime, timeAgo } from '../lib/format'
import { linesForStep, parseStepQuery, stepQueryValue } from '../lib/logSteps'

const props = defineProps<{ id: number }>()

const run = useResource(() => runsApi.get(props.id))
const now = useNow(1000)
const toasts = useToasts()

// Step statuses come from the run detail; it is refreshed whenever the log moves.
let refreshQueued = false
function refreshRun() {
  if (refreshQueued) return
  refreshQueued = true
  void run.reload().finally(() => (refreshQueued = false))
}

const stream = useLogStream((after, limit) => runsApi.logs(props.id, after, limit), {
  interval: 1500,
  onUpdate: refreshRun,
})
// Final refresh once the stream is done, so durations and statuses settle.
watch(stream.polling, (polling) => {
  if (!polling) refreshRun()
})

const status = computed(() => stream.status.value ?? run.data.value?.status ?? null)
const active = computed(() => status.value !== null && isActive(status.value))

// View mode: all steps (default) or one focused step, kept in the URL as ?step=N (1-based).
const route = useRoute()
const router = useRouter()
const selectedStep = computed(() => parseStepQuery(route.query.step, run.data.value?.steps.length))
const focusedStep = computed(() =>
  selectedStep.value === null ? null : (run.data.value?.steps.find((s) => s.position === selectedStep.value) ?? null),
)
const visibleLines = computed(() =>
  selectedStep.value === null ? stream.lines.value : linesForStep(stream.lines.value, selectedStep.value),
)
const focusedEmptyText = computed(() => {
  const step = focusedStep.value
  if (!step) return undefined
  if (step.status === 'pending') return 'This step has not started yet.'
  if (step.status === 'skipped') return 'This step was skipped.'
  return step.status === 'running' ? 'Waiting for output…' : 'This step produced no output.'
})

function selectStep(position: number | null) {
  const query = { ...route.query }
  if (position === null) delete query.step
  else query.step = stepQueryValue(position)
  void router.replace({ query }).then(() => {
    // Stacked layout: the log sits below the step list, so bring it into view.
    if (position !== null && window.matchMedia('(max-width: 900px)').matches) {
      document.getElementById('log-title')?.closest('section')?.scrollIntoView({ block: 'start', behavior: 'smooth' })
    }
  })
}

function onKeydown(event: KeyboardEvent) {
  if (event.key !== 'Escape' || selectedStep.value === null || event.defaultPrevented) return
  if (event.target instanceof HTMLElement && event.target.closest('input, textarea, select, [contenteditable]')) return
  selectStep(null)
}
onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))

const duration = computed(() => {
  const data = run.data.value
  if (!data) return '—'
  if (data.duration_ms !== null) return formatDuration(data.duration_ms)
  return data.started_at ? formatDuration(elapsedSince(data.started_at, now.value)) : '—'
})

useBreadcrumbs(() => [
  { label: 'Projects', to: '/projects' },
  ...(run.data.value?.project.name
    ? [{ label: run.data.value.project.name, to: `/projects/${run.data.value.project.id}` }]
    : []),
  { label: `Run #${props.id}` },
])

const cancelling = ref(false)
const cancelError = ref<string | null>(null)

async function cancel() {
  cancelling.value = true
  cancelError.value = null
  try {
    const result = await runsApi.cancel(props.id)
    toasts.push(result.result === 'cancelled' ? 'Run cancelled.' : 'Cancellation requested; stopping the current step…', 'info')
    refreshRun()
  } catch (e) {
    cancelError.value = e instanceof Error ? e.message : String(e)
  } finally {
    cancelling.value = false
  }
}
</script>

<template>
  <div class="page run-page">
    <InlineAlert v-if="run.error.value && !run.data.value" title="Could not load this run">{{ run.error.value }}</InlineAlert>

    <template v-if="run.data.value">
      <header class="run-header">
        <div class="title-row">
          <h1>
            Run #{{ run.data.value.id }}
            <RouterLink v-if="run.data.value.project.name" :to="`/projects/${run.data.value.project.id}`" class="project">
              {{ run.data.value.project.name }}
            </RouterLink>
          </h1>
          <StatusBadge v-if="status" :status="status" />
        </div>
        <dl class="meta">
          <div>
            <dt>Trigger</dt>
            <dd class="mono">{{ triggerSummary(run.data.value) }}</dd>
          </div>
          <div v-if="run.data.value.trigger_meta?.message" class="message">
            <dt>Commit</dt>
            <dd>{{ run.data.value.trigger_meta.message }}<span v-if="run.data.value.trigger_meta.author" class="faint"> — {{ run.data.value.trigger_meta.author }}</span></dd>
          </div>
          <div>
            <dt>Started</dt>
            <dd :title="fullDateTime(run.data.value.started_at)">
              {{ run.data.value.started_at ? timeAgo(run.data.value.started_at, now) : 'Not started' }}
            </dd>
          </div>
          <div>
            <dt>Duration</dt>
            <dd class="mono">{{ duration }}</dd>
          </div>
        </dl>
        <div v-if="active" class="actions">
          <button
            type="button"
            class="btn btn-danger"
            :disabled="cancelling || run.data.value.cancel_requested"
            @click="cancel"
          >
            <AppIcon name="ban" :size="15" />
            {{ run.data.value.cancel_requested ? 'Cancelling…' : status === 'queued' ? 'Cancel run' : 'Cancel' }}
          </button>
        </div>
      </header>

      <InlineAlert v-if="cancelError">{{ cancelError }}</InlineAlert>
      <InlineAlert v-if="run.data.value.error && status === 'failed'" title="Run failed">{{ run.data.value.error }}</InlineAlert>

      <div class="grid" :class="{ focused: focusedStep }">
        <section class="card steps-card" aria-labelledby="steps-title">
          <div class="card-header">
            <h2 id="steps-title">Steps</h2>
            <span class="faint">{{ run.data.value.steps.length }}</span>
          </div>
          <StepList
            v-if="run.data.value.steps.length"
            :steps="run.data.value.steps"
            :selected="selectedStep"
            @select="selectStep"
          />
          <p v-else class="empty muted">{{ status === 'queued' ? 'Waiting for the runner to start…' : 'No steps were recorded.' }}</p>
        </section>

        <section class="card log-card" aria-labelledby="log-title">
          <div class="card-header">
            <h2 v-if="focusedStep" id="log-title" class="focus-title">
              <StatusIcon :status="focusedStep.status" :size="15" />
              <span class="truncate">{{ focusedStep.name }}</span>
              <span class="faint step-of">Step {{ focusedStep.position + 1 }} of {{ run.data.value.steps.length }}</span>
            </h2>
            <h2 v-else id="log-title">Log</h2>
            <div class="log-tools">
              <span v-if="stream.polling.value && active" class="live"><span class="dot" aria-hidden="true" /> Live</span>
              <span v-else-if="stream.error.value" class="error-text">{{ stream.error.value }}</span>
              <button v-if="focusedStep" type="button" class="btn btn-sm" aria-keyshortcuts="Escape" @click="selectStep(null)">
                All steps <kbd class="key">Esc</kbd>
              </button>
            </div>
          </div>
          <LogView
            :lines="visibleLines"
            :live="focusedStep ? focusedStep.status === 'running' : active"
            :view-key="selectedStep === null ? 'all' : `step-${selectedStep}`"
            :follow-on-reset="focusedStep ? focusedStep.status === 'running' : true"
            :empty-text="focusedEmptyText"
            :class="{ tall: focusedStep }"
          />
        </section>
      </div>
    </template>

    <p v-else-if="run.loading.value" class="muted">Loading run…</p>
  </div>
</template>

<style scoped>
.run-header {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: var(--space-3) var(--space-5);
  align-items: start;
}
.title-row { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-3); }
.project { margin-left: var(--space-2); color: var(--text-muted); font-weight: 500; }
.project:hover { color: var(--accent); }
.meta {
  grid-column: 1 / -1;
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2) var(--space-6);
  margin: 0;
}
.meta div { min-width: 0; }
.meta dt { color: var(--text-faint); font-size: 12px; }
.meta dd { margin: 0; overflow-wrap: anywhere; }
.meta .message { flex-basis: 100%; order: 5; }
.actions { grid-row: 1; grid-column: 2; }
.grid {
  display: grid;
  grid-template-columns: minmax(240px, 300px) minmax(0, 1fr);
  gap: var(--space-4);
  align-items: start;
}
.grid.focused { grid-template-columns: minmax(200px, 240px) minmax(0, 1fr); }
.focus-title { display: flex; align-items: center; gap: var(--space-2); min-width: 0; }
.truncate { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
.step-of { flex: none; font-size: 12.5px; font-weight: 400; }
.log-tools { display: flex; align-items: center; gap: var(--space-3); flex: none; }
.key {
  padding: 0 4px;
  border: 1px solid var(--border-strong);
  border-radius: 4px;
  color: var(--text-faint);
  font-size: 11px;
  line-height: 16px;
}
.steps-card { position: sticky; top: calc(var(--topbar-h) + var(--space-4)); }
.log-card { display: flex; flex-direction: column; min-width: 0; overflow: hidden; scroll-margin-top: calc(var(--topbar-h) + var(--space-4)); }
.empty { margin: 0; padding: var(--space-4); }
.live { display: inline-flex; align-items: center; gap: 6px; color: var(--info); font-size: 12.5px; }
.dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; animation: pulse 1.6s ease-in-out infinite; }
.error-text { color: var(--danger); font-size: 12.5px; }
@keyframes pulse { 50% { opacity: 0.3; } }
@media (prefers-reduced-motion: reduce) { .dot { animation: none; } }
@media (max-width: 900px) {
  .grid, .grid.focused { grid-template-columns: minmax(0, 1fr); }
  .steps-card { position: static; }
}
@media (max-width: 560px) {
  .step-of, .key { display: none; }
  .run-header { grid-template-columns: 1fr; }
  .actions { grid-row: auto; grid-column: 1; }
}
</style>
