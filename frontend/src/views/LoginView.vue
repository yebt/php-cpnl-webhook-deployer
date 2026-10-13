<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError } from '../api/client'
import AuthLayout from '../components/layout/AuthLayout.vue'
import FormField from '../components/ui/FormField.vue'
import InlineAlert from '../components/ui/InlineAlert.vue'
import { useSession } from '../session'

const route = useRoute()
const router = useRouter()
const session = useSession()

const username = ref('')
const password = ref('')
const error = ref<string | null>(null)
const busy = ref(false)

/** Only same-app paths are accepted as a post-login destination (no open redirect). */
function destination(): string {
  const redirect = route.query.redirect
  return typeof redirect === 'string' && redirect.startsWith('/') && !redirect.startsWith('//') ? redirect : '/'
}

async function submit() {
  error.value = null
  busy.value = true
  try {
    await session.login(username.value.trim(), password.value)
    await router.replace(destination())
  } catch (e) {
    if (e instanceof ApiError && e.code === 'too_many_attempts') {
      error.value = 'Too many failed attempts from this address. Wait a few minutes and try again.'
    } else {
      error.value = e instanceof Error ? e.message : String(e)
    }
    password.value = ''
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AuthLayout title="Log in" subtitle="Use the admin account created at setup.">
    <form class="form-grid" novalidate @submit.prevent="submit">
      <InlineAlert v-if="error">{{ error }}</InlineAlert>
      <FormField id="username" label="Username" v-slot="{ id }">
        <input :id="id" v-model="username" class="input" autocomplete="username" autocapitalize="off" required />
      </FormField>
      <FormField id="password" label="Password" v-slot="{ id }">
        <input :id="id" v-model="password" class="input" type="password" autocomplete="current-password" required />
      </FormField>
      <button class="btn btn-primary" type="submit" :disabled="busy || !username || !password">
        {{ busy ? 'Logging in…' : 'Log in' }}
      </button>
      <p class="faint hint">Forgot the password? Reset it on the server with <code>php index.php user:password</code>.</p>
    </form>
  </AuthLayout>
</template>

<style scoped>
.hint { margin: 0; font-size: 12.5px; }
</style>
