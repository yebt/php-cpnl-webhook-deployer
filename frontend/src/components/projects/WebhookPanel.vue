<script setup lang="ts">
import { computed, ref } from 'vue'
import { projectsApi } from '../../api'
import type { Project } from '../../api/types'
import { absoluteAppUrl } from '../../lib/base'
import AppIcon from '../ui/AppIcon.vue'
import CopyButton from '../ui/CopyButton.vue'
import InlineAlert from '../ui/InlineAlert.vue'

const props = defineProps<{ project: Project }>()
const emit = defineEmits<{ tokenChanged: [] }>()

const url = computed(() => absoluteAppUrl(props.project.webhook_path))
const HEADER = 'X-Deployer-Token'

/** The plain token exists only in this component's memory, right after generation. */
const token = ref<string | null>(null)
const busy = ref(false)
const error = ref<string | null>(null)

const curl = computed(
  () => `curl -fsS -X POST "${url.value}" \\\n  -H "${HEADER}: ${token.value ?? '$DEPLOYER_TOKEN'}"`,
)

async function regenerate() {
  if (
    props.project.has_webhook_token &&
    !window.confirm('Generate a new token? The current token stops working immediately; update your CI secret afterwards.')
  ) {
    return
  }
  busy.value = true
  error.value = null
  try {
    token.value = (await projectsApi.regenerateToken(props.project.id)).token
    emit('tokenChanged')
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="card" aria-labelledby="webhook-title">
    <div class="card-header">
      <h2 id="webhook-title"><AppIcon name="webhook" :size="15" class="icon" /> Webhook</h2>
      <span class="status" :class="{ ok: project.has_webhook_token }">
        {{ project.has_webhook_token ? 'Token configured' : 'No token yet' }}
      </span>
    </div>
    <div class="card-body body">
      <p class="muted intro">
        A <code>POST</code> to this URL with the token in the <code>{{ HEADER }}</code> header queues a deployment.
      </p>

      <dl class="pairs">
        <dt>URL</dt>
        <dd>
          <code class="value">{{ url }}</code>
          <CopyButton :text="url" />
        </dd>
        <dt>Header</dt>
        <dd>
          <code class="value">{{ HEADER }}</code>
        </dd>
      </dl>

      <InlineAlert v-if="token" kind="warning" title="Copy this token now">
        It is shown only once; only a hash is stored. Save it as a CI secret (e.g. <code>DEPLOYER_TOKEN</code>).
        <div class="token-row">
          <code class="value token">{{ token }}</code>
          <CopyButton :text="token" label="Copy token" />
        </div>
      </InlineAlert>
      <InlineAlert v-if="error">{{ error }}</InlineAlert>

      <div>
        <div class="example-head">
          <span class="faint">Example</span>
          <CopyButton :text="curl" />
        </div>
        <pre class="example">{{ curl }}</pre>
      </div>

      <div>
        <button type="button" class="btn" :disabled="busy" @click="regenerate">
          <AppIcon name="key" :size="15" />
          {{ busy ? 'Generating…' : project.has_webhook_token ? 'Regenerate token' : 'Generate token' }}
        </button>
      </div>
    </div>
  </section>
</template>

<style scoped>
h2 { display: flex; align-items: center; gap: var(--space-2); }
.icon { color: var(--text-muted); }
.status { color: var(--text-faint); font-size: 12.5px; }
.status.ok { color: var(--success); }
.body { display: grid; grid-template-columns: minmax(0, 1fr); gap: var(--space-4); }
.intro { margin: 0; }
.pairs { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: var(--space-2) var(--space-4); margin: 0; align-items: center; }
.pairs dt { color: var(--text-faint); font-size: 12.5px; }
.pairs dd { display: flex; align-items: center; gap: var(--space-2); margin: 0; min-width: 0; }
.value {
  padding: 3px 8px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--bg-sunken);
  overflow-wrap: anywhere;
  min-width: 0;
}
.token-row { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); margin-top: var(--space-2); }
.token { user-select: all; }
.example-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--space-1); }
.example {
  margin: 0;
  padding: var(--space-3);
  border-radius: var(--radius-sm);
  background: var(--log-bg);
  color: #d7dbe0;
  overflow-x: auto;
  white-space: pre;
}
@media (max-width: 560px) {
  .pairs { grid-template-columns: 1fr; }
  .pairs dd { flex-wrap: wrap; }
}
</style>
