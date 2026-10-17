<script setup lang="ts">
import { reactive } from 'vue'
import type { Notifier, NotifierInput } from '../../api/types'
import FormField from '../ui/FormField.vue'

const props = defineProps<{ notifier: Notifier | null; fieldErrors: Record<string, string>; busy: boolean }>()
const emit = defineEmits<{ submit: [input: NotifierInput]; cancel: [] }>()

const form = reactive({
  name: props.notifier?.name ?? '',
  chat_id: props.notifier?.chat_id ?? '',
  bot_token: '',
  thread_id: props.notifier?.thread_id != null ? String(props.notifier.thread_id) : '',
})

function submit() {
  const thread = form.thread_id.trim()
  const input: NotifierInput = {
    name: form.name,
    type: 'telegram',
    chat_id: form.chat_id,
    // Sent as typed: the API validates it and reports a field error.
    thread_id: thread === '' ? null : thread,
  }
  // On edit an empty token keeps the stored one.
  if (form.bot_token !== '' || !props.notifier) input.bot_token = form.bot_token
  emit('submit', input)
}
</script>

<template>
  <form class="form-grid" novalidate @submit.prevent="submit">
    <FormField id="name" label="Name" hint="Shown in project settings, e.g. “Ops chat”." :error="fieldErrors.name" v-slot="{ id, invalid, describedBy }">
      <input :id="id" v-model="form.name" class="input" maxlength="100" required :aria-invalid="invalid" :aria-describedby="describedBy" />
    </FormField>
    <FormField
      id="bot-token"
      label="Bot token"
      :hint="notifier ? `Stored: ${notifier.bot_token_masked}. Leave empty to keep it.` : 'From @BotFather, e.g. 123456789:AA…'"
      :error="fieldErrors.bot_token"
      v-slot="{ id, invalid, describedBy }"
    >
      <input
        :id="id"
        v-model="form.bot_token"
        class="input mono"
        type="password"
        autocomplete="off"
        spellcheck="false"
        :required="!notifier"
        :aria-invalid="invalid"
        :aria-describedby="describedBy"
      />
    </FormField>
    <FormField
      id="chat-id"
      label="Chat id"
      hint="Numeric id (groups start with -100…) or @channel_name. The bot must be a member of the chat."
      :error="fieldErrors.chat_id"
      v-slot="{ id, invalid, describedBy }"
    >
      <input :id="id" v-model="form.chat_id" class="input mono" spellcheck="false" required :aria-invalid="invalid" :aria-describedby="describedBy" />
    </FormField>
    <FormField
      id="thread-id"
      label="Thread ID (optional)"
      hint="Only for groups with Topics enabled: the topic's id (the number after the last “/” in a topic message link). Empty posts to the General topic."
      :error="fieldErrors.thread_id"
      v-slot="{ id, invalid, describedBy }"
    >
      <input
        :id="id"
        v-model="form.thread_id"
        class="input mono"
        inputmode="numeric"
        spellcheck="false"
        :aria-invalid="invalid"
        :aria-describedby="describedBy"
      />
    </FormField>
    <div class="form-actions">
      <button type="button" class="btn" @click="emit('cancel')">Cancel</button>
      <button type="submit" class="btn btn-primary" :disabled="busy || !form.name || !form.chat_id || (!notifier && !form.bot_token)">
        {{ busy ? 'Saving…' : notifier ? 'Save changes' : 'Create notifier' }}
      </button>
    </div>
  </form>
</template>
