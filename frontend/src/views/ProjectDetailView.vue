<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { notifiersApi, projectsApi, runsApi } from '../api'
import { ApiError } from '../api/client'
import { isActive, type RunSummary } from '../api/types'
import ActionsPanel from '../components/projects/ActionsPanel.vue'
import WebhookPanel from '../components/projects/WebhookPanel.vue'
import RunsTable from '../components/runs/RunsTable.vue'
import AppIcon from '../components/ui/AppIcon.vue'
import EmptyState from '../components/ui/EmptyState.vue'
import InlineAlert from '../components/ui/InlineAlert.vue'
import { useBreadcrumbs } from '../composables/useBreadcrumbs'
import { useConfirm } from '../composables/useConfirm'
import { usePolling } from '../composables/usePolling'
import { useResource } from '../composables/useResource'
import { useToasts } from '../composables/useToasts'

const props = defineProps<{ id: number }>()
const router = useRouter()
const toasts = useToasts()
const confirm = useConfirm()

const PAGE = 20
const project = useResource(() => projectsApi.get(props.id))
const notifiers = useResource(() => notifiersApi.list())
const runs = ref<RunSummary[]>([])
const hasMore = ref(false)
const runsError = ref<string | null>(null)
const runsLoaded = ref(false)

async function loadRuns(reset = true) {
  try {
    const before = reset ? undefined : runs.value[runs.value.length - 1]?.id
    const page = await runsApi.list({ project: props.id, limit: PAGE, before })
    runs.value = reset ? page : [...runs.value, ...page]
    hasMore.value = page.length === PAGE
    runsError.value = null
  } catch (e) {
    runsError.value = e instanceof Error ? e.message : String(e)
  } finally {
    runsLoaded.value = true
  }
}
void loadRuns()
usePolling(
  computed(() => runs.value.length <= PAGE && runs.value.some((r) => isActive(r.status))),
  () => loadRuns(),
  4000,
)

const notifierName = computed(() => {
  const id = project.data.value?.notifier_id
  return id ? (notifiers.data.value?.find((n) => n.id === id)?.name ?? `#${id}`) : null
})

useBreadcrumbs(() => [{ label: 'Projects', to: '/projects' }, { label: project.data.value?.name ?? '…' }])

const deploying = ref(false)
const actionError = ref<string | null>(null)

async function deploy() {
  deploying.value = true
  actionError.value = null
  try {
    const { run_id } = await projectsApi.deploy(props.id)
    await router.push(`/runs/${run_id}`)
  } catch (e) {
    actionError.value = e instanceof Error ? e.message : String(e)
  } finally {
    deploying.value = false
  }
}

async function remove() {
  const name = project.data.value?.name ?? 'this project'
  const confirmed = await confirm({
    title: `Delete "${name}"?`,
    message: 'Its run history is deleted too. Files on the server are not touched.',
    confirmLabel: 'Delete project',
    danger: true,
  })
  if (!confirmed) return
  actionError.value = null
  try {
    await projectsApi.remove(props.id)
    toasts.push('Project deleted.')
    await router.push('/projects')
  } catch (e) {
    actionError.value =
      e instanceof ApiError && e.code === 'project_busy'
        ? 'This project has queued or running runs. Cancel them first, then delete.'
        : e instanceof Error
          ? e.message
          : String(e)
  }
}
</script>

<template>
  <div class="page">
    <InlineAlert v-if="project.error.value && !project.data.value" title="Could not load this project">
      {{ project.error.value }}
    </InlineAlert>

    <template v-if="project.data.value">
      <header class="page-head">
        <div class="title">
          <h1>{{ project.data.value.name }}</h1>
          <dl class="meta">
            <div><dt>Folder</dt><dd class="mono">{{ project.data.value.folder }}</dd></div>
            <div><dt>Instructions</dt><dd class="mono">{{ project.data.value.instructions_path }}</dd></div>
            <div><dt>Notifier</dt><dd>{{ notifierName ?? 'None' }}</dd></div>
          </dl>
        </div>
        <div class="actions">
          <RouterLink :to="`/projects/${id}/instructions`" class="btn">
            <AppIcon name="file" :size="15" />
            Edit instructions
          </RouterLink>
          <RouterLink :to="`/projects/${id}/edit`" class="btn">
            <AppIcon name="edit" :size="15" />
            Settings
          </RouterLink>
          <button type="button" class="btn btn-primary" :disabled="deploying" @click="deploy">
            <AppIcon name="rocket" :size="15" />
            {{ deploying ? 'Queuing…' : 'Deploy' }}
          </button>
        </div>
      </header>

      <InlineAlert v-if="actionError">{{ actionError }}</InlineAlert>

      <div class="grid">
        <section class="card runs" aria-labelledby="history-title">
          <div class="card-header">
            <h2 id="history-title">Run history</h2>
          </div>
          <InlineAlert v-if="runsError" class="inset">{{ runsError }}</InlineAlert>
          <RunsTable v-if="runs.length" :runs="runs" :show-project="false" />
          <EmptyState v-else-if="runsLoaded" title="No runs yet" text="Press Deploy, run an action or call the webhook to start the first run." />
          <div v-if="hasMore" class="more">
            <button type="button" class="btn btn-sm" @click="loadRuns(false)">Load older runs</button>
          </div>
        </section>

        <div class="side">
          <ActionsPanel :project="project.data.value" />
          <WebhookPanel :project="project.data.value" @token-changed="project.reload()" />

          <section class="card danger" aria-labelledby="danger-title">
            <div class="card-header"><h2 id="danger-title">Delete project</h2></div>
            <div class="card-body danger-body">
              <p class="muted">Removes the project and its run history. The folder and instructions file stay on disk.</p>
              <button type="button" class="btn btn-danger" @click="remove">
                <AppIcon name="trash" :size="15" />
                Delete project
              </button>
            </div>
          </section>
        </div>
      </div>
    </template>
    <p v-else-if="project.loading.value" class="muted">Loading…</p>
  </div>
</template>

<style scoped>
.page-head { display: flex; flex-wrap: wrap; justify-content: space-between; gap: var(--space-4); }
.title { min-width: 0; display: grid; gap: var(--space-2); }
.meta { display: flex; flex-wrap: wrap; gap: var(--space-1) var(--space-5); margin: 0; }
.meta dt { color: var(--text-faint); font-size: 12px; }
.meta dd { margin: 0; overflow-wrap: anywhere; }
.actions { display: flex; flex-wrap: wrap; align-items: flex-start; gap: var(--space-2); }
.grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(320px, 420px); gap: var(--space-4); align-items: start; }
.side { display: grid; grid-template-columns: minmax(0, 1fr); gap: var(--space-4); min-width: 0; }
.runs { min-width: 0; }
.inset { margin: var(--space-3); }
.more { display: flex; justify-content: center; padding: var(--space-3); border-top: 1px solid var(--border); }
.danger-body { display: grid; gap: var(--space-3); justify-items: start; }
.danger-body p { margin: 0; }
@media (max-width: 1100px) { .grid { grid-template-columns: minmax(0, 1fr); } }
</style>
