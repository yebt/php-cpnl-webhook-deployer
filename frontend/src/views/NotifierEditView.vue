<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { notifiersApi } from '../api'
import { ApiError } from '../api/client'
import type { NotifierInput } from '../api/types'
import NotifierForm from '../components/notifiers/NotifierForm.vue'
import InlineAlert from '../components/ui/InlineAlert.vue'
import { useBreadcrumbs } from '../composables/useBreadcrumbs'
import { useResource } from '../composables/useResource'
import { useToasts } from '../composables/useToasts'

const props = defineProps<{ id?: number }>()
const router = useRouter()
const toasts = useToasts()

const isNew = computed(() => props.id === undefined)
const notifier = useResource(() => (props.id === undefined ? Promise.resolve(null) : notifiersApi.get(props.id)))

useBreadcrumbs(() => [
  { label: 'Notifiers', to: '/notifiers' },
  { label: isNew.value ? 'New notifier' : (notifier.data.value?.name ?? '…') },
])

const busy = ref(false)
const error = ref<string | null>(null)
const fieldErrors = ref<Record<string, string>>({})

async function save(input: NotifierInput) {
  busy.value = true
  error.value = null
  fieldErrors.value = {}
  try {
    if (props.id === undefined) await notifiersApi.create(input)
    else await notifiersApi.update(props.id, input)
    toasts.push(isNew.value ? 'Notifier created. Use “Send test” to check it.' : 'Notifier saved.')
    await router.push('/notifiers')
  } catch (e) {
    if (e instanceof ApiError && e.code === 'validation_failed') fieldErrors.value = e.fieldErrors()
    else error.value = e instanceof Error ? e.message : String(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="page narrow">
    <header><h1>{{ isNew ? 'New notifier' : 'Edit notifier' }}</h1></header>
    <InlineAlert v-if="error || notifier.error.value">{{ error ?? notifier.error.value }}</InlineAlert>
    <section v-if="isNew || notifier.data.value" class="card card-body">
      <NotifierForm :notifier="notifier.data.value" :field-errors="fieldErrors" :busy="busy" @submit="save" @cancel="router.push('/notifiers')" />
    </section>
    <p v-else-if="notifier.loading.value" class="muted">Loading…</p>
  </div>
</template>

<style scoped>
.narrow { max-width: 640px; }
</style>
