<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { projectsApi } from '../../api'
import { ApiError } from '../../api/client'
import type { Project, ProjectAction } from '../../api/types'
import { useConfirm } from '../../composables/useConfirm'
import { useResource } from '../../composables/useResource'
import { ACTIONS_EXAMPLE, actionConfirmMessage } from '../../lib/actions'
import AppIcon from '../ui/AppIcon.vue'
import InlineAlert from '../ui/InlineAlert.vue'

const props = defineProps<{ project: Project }>()
const router = useRouter()
const confirm = useConfirm()

const list = useResource(() => projectsApi.actions(props.project.id))
const running = ref<string | null>(null)
const error = ref<string | null>(null)

async function run(action: ProjectAction) {
  const confirmed = await confirm({
    title: `Run "${action.name}"?`,
    message: actionConfirmMessage(props.project.name, action.steps),
    confirmLabel: 'Run action',
  })
  if (!confirmed) return
  running.value = action.id
  error.value = null
  try {
    const { run_id } = await projectsApi.runAction(props.project.id, action.id)
    await router.push(`/runs/${run_id}`)
  } catch (e) {
    if (e instanceof ApiError && (e.code === 'unknown_action' || e.code === 'invalid_instructions')) {
      // The file changed since the list was loaded: show its current state.
      void list.reload()
    }
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    running.value = null
  }
}
</script>

<template>
  <section class="card" aria-labelledby="actions-title">
    <div class="card-header">
      <h2 id="actions-title"><AppIcon name="bolt" :size="15" class="icon" /> Actions</h2>
      <span v-if="list.data.value?.actions.length" class="faint">{{ list.data.value.actions.length }}</span>
    </div>

    <InlineAlert v-if="list.error.value" class="inset" title="Could not load the actions">{{ list.error.value }}</InlineAlert>
    <InlineAlert v-if="error" class="inset" title="Could not run the action">{{ error }}</InlineAlert>

    <template v-if="list.data.value">
      <InlineAlert v-if="list.data.value.error" kind="warning" class="inset" title="The instructions file has errors">
        <pre class="parse-error">{{ list.data.value.error }}</pre>
        <template #actions>
          <RouterLink :to="`/projects/${project.id}/instructions`" class="btn btn-sm">Edit instructions</RouterLink>
        </template>
      </InlineAlert>

      <ul v-if="list.data.value.actions.length" class="actions-list">
        <li v-for="action in list.data.value.actions" :key="action.id" class="action">
          <div class="text">
            <p class="name">{{ action.name }} <code v-if="action.name !== action.id" class="id faint">{{ action.id }}</code></p>
            <p v-if="action.description" class="muted description">{{ action.description }}</p>
            <p class="faint steps">{{ action.steps.length }} {{ action.steps.length === 1 ? 'step' : 'steps' }}: {{ action.steps.join(' · ') }}</p>
          </div>
          <button
            type="button"
            class="btn btn-sm"
            :disabled="running !== null"
            :aria-label="`Run action ${action.name}`"
            @click="run(action)"
          >
            <AppIcon name="rocket" :size="14" />
            {{ running === action.id ? 'Queuing…' : 'Run' }}
          </button>
        </li>
      </ul>

      <div v-else-if="!list.data.value.error" class="card-body empty">
        <p class="muted">
          No actions yet. Actions are named tasks, such as clearing a cache, defined under <code>actions:</code> in the
          instructions file. They use the same queue, log and notifications as a deploy.
        </p>
        <pre class="example">{{ ACTIONS_EXAMPLE }}</pre>
        <RouterLink :to="`/projects/${project.id}/instructions`" class="btn btn-sm">
          <AppIcon name="file" :size="14" />
          Edit instructions
        </RouterLink>
      </div>
    </template>
    <p v-else-if="list.loading.value" class="card-body muted loading">Loading…</p>
  </section>
</template>

<style scoped>
h2 { display: flex; align-items: center; gap: var(--space-2); }
.icon { color: var(--text-muted); }
.inset { margin: var(--space-3); }
.actions-list { list-style: none; margin: 0; padding: 0; }
.action {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
}
.action + .action { border-top: 1px solid var(--border); }
.text { min-width: 0; display: grid; gap: 2px; }
.text p { margin: 0; overflow-wrap: anywhere; }
.name { font-weight: 500; }
.id { font-size: 12px; font-weight: 400; }
.description, .steps { font-size: 12.5px; }
.empty { display: grid; gap: var(--space-3); justify-items: start; }
.empty p { margin: 0; }
.example {
  justify-self: stretch;
  margin: 0;
  padding: var(--space-3);
  border-radius: var(--radius-sm);
  background: var(--log-bg);
  color: #d7dbe0;
  overflow-x: auto;
  white-space: pre;
}
.parse-error { margin: var(--space-1) 0 0; white-space: pre-wrap; overflow-wrap: anywhere; font-size: 12px; }
.loading { margin: 0; }
</style>
