<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { runsApi } from '../api'
import { isActive, type RunStatus, type RunSummary } from '../api/types'
import RunsTable from '../components/runs/RunsTable.vue'
import EmptyState from '../components/ui/EmptyState.vue'
import InlineAlert from '../components/ui/InlineAlert.vue'
import { useBreadcrumbs } from '../composables/useBreadcrumbs'
import { usePolling } from '../composables/usePolling'

useBreadcrumbs(() => [{ label: 'Runs' }])

const PAGE = 50
const filters: { label: string; value: RunStatus | null }[] = [
  { label: 'All', value: null },
  { label: 'Running', value: 'running' },
  { label: 'Queued', value: 'queued' },
  { label: 'Failed', value: 'failed' },
  { label: 'Success', value: 'success' },
  { label: 'Cancelled', value: 'cancelled' },
]
const status = ref<RunStatus | null>(null)
const runs = ref<RunSummary[]>([])
const hasMore = ref(false)
const loading = ref(false)
const error = ref<string | null>(null)

async function load(reset: boolean) {
  loading.value = true
  error.value = null
  try {
    const before = reset ? undefined : runs.value[runs.value.length - 1]?.id
    const page = await runsApi.list({ status: status.value ?? undefined, limit: PAGE, before })
    runs.value = reset ? page : [...runs.value, ...page]
    hasMore.value = page.length === PAGE
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}

watch(status, () => void load(true), { immediate: true })

// Refresh the first page while anything visible is still active (only when not paginated).
usePolling(
  computed(() => runs.value.length <= PAGE && runs.value.some((r) => isActive(r.status))),
  () => load(true),
  4000,
)
</script>

<template>
  <div class="page">
    <header>
      <h1>Runs</h1>
    </header>

    <div class="filters" role="group" aria-label="Filter by status">
      <button
        v-for="filter in filters"
        :key="filter.label"
        type="button"
        class="btn btn-sm"
        :class="{ selected: status === filter.value }"
        :aria-pressed="status === filter.value"
        @click="status = filter.value"
      >
        {{ filter.label }}
      </button>
    </div>

    <InlineAlert v-if="error">{{ error }}</InlineAlert>

    <section class="card">
      <RunsTable v-if="runs.length" :runs="runs" />
      <EmptyState v-else-if="!loading" :title="status ? `No ${status} runs` : 'No runs yet'" />
      <p v-else class="loading muted">Loading…</p>
    </section>

    <div v-if="hasMore" class="more">
      <button type="button" class="btn" :disabled="loading" @click="load(false)">
        {{ loading ? 'Loading…' : 'Load older runs' }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.filters { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.selected { border-color: var(--accent); color: var(--accent); }
.loading { margin: 0; padding: var(--space-4); }
.more { display: flex; justify-content: center; }
</style>
