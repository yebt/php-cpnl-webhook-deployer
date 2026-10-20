<script setup lang="ts">
import { updatesApi } from '../api'
import UpdatePanel from '../components/updates/UpdatePanel.vue'
import InlineAlert from '../components/ui/InlineAlert.vue'
import { useBreadcrumbs } from '../composables/useBreadcrumbs'
import { useConfirm } from '../composables/useConfirm'
import { useUpdates } from '../composables/useUpdates'

useBreadcrumbs(() => [{ label: 'Updates' }])

const confirm = useConfirm()
const updates = useUpdates({
  client: updatesApi,
  confirm: ({ title, message }) => confirm({ title, message, confirmLabel: 'Update' }),
  reloadPage: () => window.location.reload(),
})
</script>

<template>
  <div class="page">
    <header class="page-head">
      <h1>Updates</h1>
      <p class="muted">Install new releases of Deployer published on GitHub.</p>
    </header>

    <InlineAlert v-if="updates.loadError.value" title="Cannot check for updates">
      {{ updates.loadError.value }}
      <template #actions>
        <button type="button" class="btn btn-sm" @click="updates.reload()">Try again</button>
      </template>
    </InlineAlert>
    <InlineAlert v-if="updates.applyError.value" title="Update not applied">{{ updates.applyError.value }}</InlineAlert>

    <UpdatePanel
      v-if="updates.status.value"
      :status="updates.status.value"
      :can-update="updates.canUpdate.value"
      :applying="updates.applying.value"
      :result="updates.result.value"
      @apply="updates.apply()"
    />
    <p v-else-if="updates.loading.value" class="muted">Checking for updates…</p>
  </div>
</template>

<style scoped>
.page-head p { margin: var(--space-1) 0 0; }
</style>
