<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ApiError } from '../api/client'
import AuthLayout from '../components/layout/AuthLayout.vue'
import FormField from '../components/ui/FormField.vue'
import InlineAlert from '../components/ui/InlineAlert.vue'
import { useSession } from '../session'

const router = useRouter()
const session = useSession()

const username = ref('admin')
const password = ref('')
const confirm = ref('')
const error = ref<string | null>(null)
const fieldErrors = ref<Record<string, string>>({})
const busy = ref(false)

const mismatch = computed(() => confirm.value !== '' && confirm.value !== password.value)
const tooShort = computed(() => password.value !== '' && password.value.length < 10)

async function submit() {
  if (mismatch.value || tooShort.value) return
  error.value = null
  fieldErrors.value = {}
  busy.value = true
  try {
    await session.setup(username.value.trim(), password.value)
    await router.replace('/')
  } catch (e) {
    if (e instanceof ApiError && e.code === 'already_set_up') {
      await router.replace({ name: 'login' })
      return
    }
    fieldErrors.value = e instanceof ApiError ? e.fieldErrors() : {}
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AuthLayout title="Create the admin account" subtitle="First run: this account is the only user of this install.">
    <form class="form-grid" novalidate @submit.prevent="submit">
      <InlineAlert v-if="error">{{ error }}</InlineAlert>
      <FormField id="username" label="Username" hint="Letters, digits and . _ @ -" :error="fieldErrors.username" v-slot="{ id, invalid, describedBy }">
        <input :id="id" v-model="username" class="input" autocomplete="username" autocapitalize="off" :aria-invalid="invalid" :aria-describedby="describedBy" required />
      </FormField>
      <FormField
        id="password"
        label="Password"
        hint="At least 10 characters."
        :error="fieldErrors.password ?? (tooShort ? 'Use at least 10 characters.' : undefined)"
        v-slot="{ id, invalid, describedBy }"
      >
        <input :id="id" v-model="password" class="input" type="password" autocomplete="new-password" :aria-invalid="invalid" :aria-describedby="describedBy" required />
      </FormField>
      <FormField id="confirm" label="Confirm password" :error="mismatch ? 'Passwords do not match.' : undefined" v-slot="{ id, invalid, describedBy }">
        <input :id="id" v-model="confirm" class="input" type="password" autocomplete="new-password" :aria-invalid="invalid" :aria-describedby="describedBy" required />
      </FormField>
      <button class="btn btn-primary" type="submit" :disabled="busy || !username || !password || !confirm || mismatch || tooShort">
        {{ busy ? 'Creating…' : 'Create account' }}
      </button>
    </form>
  </AuthLayout>
</template>
