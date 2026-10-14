<script setup lang="ts">
import { computed } from 'vue'
import type { UpdateResult, UpdateStatus } from '../../api/types'
import AppIcon from '../ui/AppIcon.vue'
import InlineAlert from '../ui/InlineAlert.vue'

const props = defineProps<{
  status: UpdateStatus
  canUpdate: boolean
  applying: boolean
  result: UpdateResult | null
}>()

defineEmits<{ apply: [] }>()

const latest = computed(() => props.status.latest)
const headline = computed(() => {
  if (!latest.value) return 'No release has been published yet.'
  if (props.status.available) return `Version ${latest.value.version} is available.`
  return 'You are running the latest release.'
})
// GitHub timestamps are ISO 8601 with a zone, unlike the API's own UTC timestamps.
const publishedAt = computed(() => {
  const value = latest.value?.published_at
  const date = value ? new Date(value) : null
  return date && !Number.isNaN(date.getTime()) ? date.toLocaleString() : null
})
const blockedReason = computed(() => {
  if (!props.status.available || !latest.value) return null
  if (!props.status.can_apply) return 'This copy runs from source, not from the packaged phar. Update it with git instead.'
  if (props.status.current_is_dev) return 'This is a development build. Replace it from the command line with `php index.php self-update --force`.'
  return null
})
</script>

<template>
  <section class="card">
    <div class="card-body body">
      <dl class="versions">
        <div>
          <dt class="faint">Installed</dt>
          <dd class="mono">{{ status.current }}</dd>
        </div>
        <div>
          <dt class="faint">Latest release</dt>
          <dd class="mono">{{ latest?.version ?? '—' }}</dd>
        </div>
      </dl>

      <p class="headline" role="status">{{ headline }}</p>

      <div v-if="latest" class="release">
        <p class="faint meta">
          {{ latest.name }}<template v-if="publishedAt"> · published {{ publishedAt }}</template>
        </p>
        <pre v-if="latest.notes_excerpt" class="notes">{{ latest.notes_excerpt }}</pre>
        <a v-if="latest.url" :href="latest.url" target="_blank" rel="noopener noreferrer" class="link">
          Release notes on GitHub
          <AppIcon name="external" :size="13" />
        </a>
      </div>

      <InlineAlert v-if="blockedReason" kind="info">{{ blockedReason }}</InlineAlert>
      <InlineAlert v-else-if="canUpdate && status.active_runs > 0" kind="warning">
        {{ status.active_runs === 1 ? '1 run is' : `${status.active_runs} runs are` }} queued or running. They finish on the
        current version; later runs use the new one.
      </InlineAlert>

      <InlineAlert v-if="result?.updated" kind="info" title="Update installed">
        Deployer {{ result.to }} replaced {{ result.from }}. The previous file was kept as
        <span class="mono">{{ result.backup_file }}</span>. Reloading…
      </InlineAlert>

      <div v-if="canUpdate && !result?.updated" class="actions">
        <button type="button" class="btn btn-primary" :disabled="applying" @click="$emit('apply')">
          <AppIcon :name="applying ? 'spinner' : 'update'" :size="15" :class="{ spin: applying }" />
          {{ applying ? 'Updating…' : `Update to ${latest?.version}` }}
        </button>
      </div>
    </div>
  </section>
</template>

<style scoped>
.body { display: grid; gap: var(--space-4); }
.versions { display: flex; flex-wrap: wrap; gap: var(--space-6); margin: 0; }
.versions dt { font-size: 12px; text-transform: uppercase; letter-spacing: 0.06em; }
.versions dd { margin: var(--space-1) 0 0; font-size: 20px; font-weight: 600; }
.headline { margin: 0; font-weight: 600; }
.release { display: grid; gap: var(--space-2); }
.meta { margin: 0; font-size: 13px; }
.notes {
  margin: 0;
  max-height: 240px;
  overflow: auto;
  padding: var(--space-3);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--bg-sunken);
  font-size: 12.5px;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.link { display: inline-flex; align-items: center; gap: var(--space-1); width: fit-content; }
.actions { display: flex; gap: var(--space-2); }
.spin { animation: spin 0.9s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .spin { animation: none; } }
</style>
