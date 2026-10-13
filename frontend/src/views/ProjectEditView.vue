<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { notifiersApi, projectsApi } from '../api'
import { ApiError } from '../api/client'
import type { ProjectInput } from '../api/types'
import ProjectForm from '../components/projects/ProjectForm.vue'
import InlineAlert from '../components/ui/InlineAlert.vue'
import { useBreadcrumbs } from '../composables/useBreadcrumbs'
import { useResource } from '../composables/useResource'
import { useToasts } from '../composables/useToasts'

const props = defineProps<{ id?: number }>()
const router = useRouter()
const toasts = useToasts()

const isNew = computed(() => props.id === undefined)
const project = useResource(() => (props.id === undefined ? Promise.resolve(null) : projectsApi.get(props.id)))
const notifiers = useResource(() => notifiersApi.list())

useBreadcrumbs(() => [
  { label: 'Projects', to: '/projects' },
  ...(project.data.value ? [{ label: project.data.value.name, to: `/projects/${project.data.value.id}` }] : []),
  { label: isNew.value ? 'New project' : 'Settings' },
])

const busy = ref(false)
const error = ref<string | null>(null)
const fieldErrors = ref<Record<string, string>>({})

async function save(input: ProjectInput) {
  busy.value = true
  error.value = null
  fieldErrors.value = {}
  try {
    const saved = props.id === undefined ? await projectsApi.create(input) : await projectsApi.update(props.id, input)
    toasts.push(isNew.value ? 'Project created.' : 'Project saved.')
    await router.push(`/projects/${saved.id}`)
  } catch (e) {
    if (e instanceof ApiError && e.code === 'slug_taken') {
      fieldErrors.value = { slug: e.message }
    } else if (e instanceof ApiError && e.code === 'validation_failed') {
      fieldErrors.value = e.fieldErrors()
    } else {
      error.value = e instanceof Error ? e.message : String(e)
    }
  } finally {
    busy.value = false
  }
}

function cancel() {
  void router.push(props.id === undefined ? '/projects' : `/projects/${props.id}`)
}
</script>

<template>
  <div class="page narrow">
    <header>
      <h1>{{ isNew ? 'New project' : 'Project settings' }}</h1>
    </header>
    <InlineAlert v-if="error">{{ error }}</InlineAlert>
    <InlineAlert v-if="project.error.value">{{ project.error.value }}</InlineAlert>
    <section v-if="(isNew || project.data.value) && notifiers.data.value" class="card card-body">
      <ProjectForm
        :project="project.data.value"
        :notifiers="notifiers.data.value"
        :field-errors="fieldErrors"
        :busy="busy"
        @submit="save"
        @cancel="cancel"
      />
    </section>
    <p v-else-if="!project.error.value" class="muted">Loading…</p>
  </div>
</template>

<style scoped>
.narrow { max-width: 760px; }
</style>
