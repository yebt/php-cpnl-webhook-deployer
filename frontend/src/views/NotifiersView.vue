<script setup lang="ts">
import { ref } from 'vue'
import { notifiersApi } from '../api'
import type { Notifier } from '../api/types'
import AppIcon from '../components/ui/AppIcon.vue'
import EmptyState from '../components/ui/EmptyState.vue'
import InlineAlert from '../components/ui/InlineAlert.vue'
import { useBreadcrumbs } from '../composables/useBreadcrumbs'
import { useConfirm } from '../composables/useConfirm'
import { useResource } from '../composables/useResource'
import { useToasts } from '../composables/useToasts'

useBreadcrumbs(() => [{ label: 'Notifiers' }])

const toasts = useToasts()
const confirm = useConfirm()
const notifiers = useResource(() => notifiersApi.list())
const testing = ref<number | null>(null)
const results = ref<Record<number, { ok: boolean; message: string }>>({})
const error = ref<string | null>(null)

async function test(notifier: Notifier) {
  testing.value = notifier.id
  try {
    await notifiersApi.test(notifier.id)
    results.value = { ...results.value, [notifier.id]: { ok: true, message: 'Test message sent.' } }
  } catch (e) {
    results.value = { ...results.value, [notifier.id]: { ok: false, message: e instanceof Error ? e.message : String(e) } }
  } finally {
    testing.value = null
  }
}

async function remove(notifier: Notifier) {
  const confirmed = await confirm({
    title: `Delete "${notifier.name}"?`,
    message: 'Projects using it stop sending notifications.',
    confirmLabel: 'Delete notifier',
    danger: true,
  })
  if (!confirmed) return
  error.value = null
  try {
    await notifiersApi.remove(notifier.id)
    toasts.push('Notifier deleted.')
    await notifiers.reload()
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  }
}
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>Notifiers</h1>
        <p class="muted">Telegram chats that receive a message when a run finishes.</p>
      </div>
      <RouterLink to="/notifiers/new" class="btn btn-primary">
        <AppIcon name="plus" :size="15" />
        New notifier
      </RouterLink>
    </header>

    <InlineAlert v-if="notifiers.error.value || error">{{ notifiers.error.value ?? error }}</InlineAlert>

    <section class="card">
      <ul v-if="notifiers.data.value?.length" class="list">
        <li v-for="notifier in notifiers.data.value" :key="notifier.id" class="item">
          <div class="info">
            <span class="name">{{ notifier.name }}</span>
            <span class="faint mono detail">Telegram · chat {{ notifier.chat_id }}<template v-if="notifier.thread_id"> · topic {{ notifier.thread_id }}</template> · token {{ notifier.bot_token_masked }}</span>
            <span
              v-if="results[notifier.id]"
              class="result"
              :class="results[notifier.id]!.ok ? 'ok' : 'bad'"
              role="status"
            >
              {{ results[notifier.id]!.message }}
            </span>
          </div>
          <div class="actions">
            <button type="button" class="btn btn-sm" :disabled="testing === notifier.id" @click="test(notifier)">
              <AppIcon name="send" :size="14" />
              {{ testing === notifier.id ? 'Sending…' : 'Send test' }}
            </button>
            <RouterLink :to="`/notifiers/${notifier.id}/edit`" class="btn btn-sm">
              <AppIcon name="edit" :size="14" />
              Edit
            </RouterLink>
            <button type="button" class="btn btn-sm btn-danger" :aria-label="`Delete ${notifier.name}`" @click="remove(notifier)">
              <AppIcon name="trash" :size="14" />
            </button>
          </div>
        </li>
      </ul>
      <EmptyState
        v-else-if="notifiers.data.value"
        title="No notifiers yet"
        text="Create a Telegram bot with @BotFather, add it to your chat, then register it here."
      >
        <RouterLink to="/notifiers/new" class="btn btn-primary">Add a notifier</RouterLink>
      </EmptyState>
      <p v-else-if="notifiers.loading.value" class="loading muted">Loading…</p>
    </section>
  </div>
</template>

<style scoped>
.page-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: var(--space-3); }
.page-head p { margin: var(--space-1) 0 0; }
.list { margin: 0; padding: 0; list-style: none; }
.item {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  border-bottom: 1px solid var(--border);
}
.item:last-child { border-bottom: 0; }
.info { display: grid; gap: 2px; min-width: 0; }
.name { font-weight: 600; }
.detail { font-size: 12px; overflow-wrap: anywhere; }
.result { font-size: 12.5px; }
.ok { color: var(--success); }
.bad { color: var(--danger); }
.actions { display: flex; gap: var(--space-2); }
.loading { margin: 0; padding: var(--space-4); }
</style>
