<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import { projectsApi } from '../api'
import { ApiError } from '../api/client'
import YamlEditor from '../components/editor/YamlEditor.vue'
import InlineAlert from '../components/ui/InlineAlert.vue'
import { useBreadcrumbs } from '../composables/useBreadcrumbs'
import { useResource } from '../composables/useResource'
import { useToasts } from '../composables/useToasts'

const props = defineProps<{ id: number }>()
const toasts = useToasts()

const STARTER = `# Steps run in order inside the project folder. A step stops at its first
# failing command (set "strict: false" on a step to only check the last one).
vars:                      # text replaced in commands before they run: \${NAME}
  BRANCH: main
env:                       # environment variables for every step: $NAME
  APP_ENV: production
steps:
  - name: Fetch
    run: git fetch origin \${BRANCH} && git reset --hard origin/\${BRANCH}
  - name: Install
    run:                   # a list runs in one shell, one command after another
      - composer install --no-dev --no-interaction
      - composer dump-autoload --optimize --no-dev
    timeout: 600
    env:
      COMPOSER_MEMORY_LIMIT: "-1"
`

const project = useResource(() => projectsApi.get(props.id))
const content = ref('')
const saved = ref('')
const path = ref('')
const exists = ref(true)
const loadError = ref<string | null>(null)
const loaded = ref(false)

/** Errors of the last save attempt (422) or of the stored file as loaded. */
const errors = ref<string[]>([])
const rejected = ref(false)
const saveError = ref<string | null>(null)
const saving = ref(false)

const dirty = computed(() => loaded.value && content.value !== saved.value)

useBreadcrumbs(() => [
  { label: 'Projects', to: '/projects' },
  { label: project.data.value?.name ?? '…', to: `/projects/${props.id}` },
  { label: 'Instructions' },
])

async function load() {
  try {
    const file = await projectsApi.instructions(props.id)
    path.value = file.path
    exists.value = file.exists
    content.value = file.exists ? file.content : STARTER
    saved.value = file.exists ? file.content : ''
    errors.value = file.exists ? file.errors : []
    loaded.value = true
  } catch (e) {
    loadError.value = e instanceof Error ? e.message : String(e)
  }
}
void load()

async function save(force = false) {
  if (saving.value) return
  saving.value = true
  saveError.value = null
  try {
    const result = await projectsApi.saveInstructions(props.id, content.value, force)
    saved.value = content.value
    exists.value = true
    errors.value = result.errors
    rejected.value = false
    toasts.push(result.valid ? 'Instructions saved.' : 'Saved with validation errors.', result.valid ? 'success' : 'info')
  } catch (e) {
    if (e instanceof ApiError && e.code === 'invalid_pipeline') {
      const list = e.details.errors
      errors.value = Array.isArray(list) ? list.map(String) : [e.message]
      rejected.value = true
    } else {
      saveError.value = e instanceof Error ? e.message : String(e)
    }
  } finally {
    saving.value = false
  }
}

function confirmLeave(): boolean {
  return !dirty.value || window.confirm('You have unsaved changes. Leave without saving?')
}
onBeforeRouteLeave(() => confirmLeave())
function beforeUnload(event: BeforeUnloadEvent) {
  if (dirty.value) event.preventDefault()
}
onMounted(() => window.addEventListener('beforeunload', beforeUnload))
onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload))
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>Instructions</h1>
        <p class="muted path"><code>{{ path || '…' }}</code></p>
      </div>
      <div class="actions">
        <span v-if="dirty" class="faint">Unsaved changes</span>
        <button type="button" class="btn btn-primary" :disabled="!loaded || saving || (!dirty && exists)" @click="save(false)">
          {{ saving ? 'Saving…' : 'Save' }}
        </button>
      </div>
    </header>

    <InlineAlert v-if="loadError" title="Could not read the instructions file">{{ loadError }}</InlineAlert>
    <InlineAlert v-if="saveError" title="Could not save">{{ saveError }}</InlineAlert>
    <InlineAlert v-if="!exists && loaded" kind="info" title="This file does not exist yet">
      A starter pipeline is loaded below. Saving creates the file; its folder must already exist.
    </InlineAlert>

    <InlineAlert
      v-if="errors.length"
      :kind="rejected ? 'error' : 'warning'"
      :title="rejected ? 'Not saved: the pipeline is invalid' : 'The saved pipeline has errors; runs will fail until they are fixed'"
    >
      <ul class="errors">
        <li v-for="(error, index) in errors" :key="index" class="mono">{{ error }}</li>
      </ul>
      <template v-if="rejected" #actions>
        <button type="button" class="btn btn-sm" :disabled="saving" @click="save(true)">Save anyway</button>
      </template>
    </InlineAlert>

    <YamlEditor v-if="loaded" v-model="content" label="Pipeline YAML" @save="save(false)" />

    <details class="card help">
      <summary>YAML reference</summary>
      <div class="card-body">
        <ul>
          <li><code>vars</code>: text replaced in commands before they run, written <code>${NAME}</code>; values may use earlier vars.</li>
          <li><code>env</code> (top level or per step): real environment variables, read with <code>$NAME</code>; values are never logged.</li>
          <li>Built-ins: <code>${PROJECT}</code>, <code>${RUN_ID}</code>, <code>${PROJECT_DIR}</code>.</li>
          <li><code>$${NAME}</code> keeps a literal <code>${NAME}</code> for the shell.</li>
          <li>Per step: <code>name</code>, <code>run</code> (a script or a list of commands), optional <code>timeout</code> (seconds, default 3600), <code>continue_on_error</code>, <code>strict</code> (default <code>true</code>: stop at the first failing command) and <code>env</code>.</li>
          <li>Save shortcut: <kbd>Ctrl</kbd>/<kbd>⌘</kbd> + <kbd>S</kbd>. Press <kbd>Esc</kbd> then <kbd>Tab</kbd> to leave the editor.</li>
        </ul>
      </div>
    </details>
  </div>
</template>

<style scoped>
.page-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: var(--space-3); }
.path { margin: var(--space-1) 0 0; overflow-wrap: anywhere; }
.actions { display: flex; align-items: center; gap: var(--space-3); }
.errors { margin: var(--space-2) 0 0; padding-left: var(--space-4); }
.help summary { padding: var(--space-3) var(--space-4); cursor: pointer; font-weight: 500; }
.help ul { margin: 0; padding-left: var(--space-4); display: grid; gap: var(--space-1); }
kbd { padding: 0 4px; border: 1px solid var(--border-strong); border-radius: 4px; font-size: 11.5px; }
</style>
