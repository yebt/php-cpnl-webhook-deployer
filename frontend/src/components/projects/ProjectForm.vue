<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import type { Notifier, Project, ProjectInput } from '../../api/types'
import FormField from '../ui/FormField.vue'

const props = defineProps<{
  project: Project | null
  notifiers: Notifier[]
  fieldErrors: Record<string, string>
  busy: boolean
}>()
const emit = defineEmits<{ submit: [input: ProjectInput]; cancel: [] }>()

const form = reactive<ProjectInput>({
  name: props.project?.name ?? '',
  slug: props.project?.slug ?? '',
  folder: props.project?.folder ?? '',
  instructions_file: props.project?.instructions_file ?? 'deployer.yml',
  notifier_id: props.project?.notifier_id ?? null,
})

// The slug follows the name until edited by hand (new projects only).
const slugTouched = ref(props.project !== null)
function slugify(value: string): string {
  return value
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 63)
}
watch(
  () => form.name,
  (name) => {
    if (!slugTouched.value) form.slug = slugify(name)
  },
)

const folderLooksRelative = computed(() => form.folder !== '' && !form.folder.startsWith('/'))
</script>

<template>
  <form class="form-grid" novalidate @submit.prevent="emit('submit', { ...form })">
    <div class="form-row">
      <FormField id="name" label="Name" :error="fieldErrors.name" v-slot="{ id, invalid, describedBy }">
        <input :id="id" v-model="form.name" class="input" maxlength="100" required :aria-invalid="invalid" :aria-describedby="describedBy" />
      </FormField>
      <FormField
        id="slug"
        label="Slug"
        hint="Used in the webhook URL: /hooks/<slug>."
        :error="fieldErrors.slug"
        v-slot="{ id, invalid, describedBy }"
      >
        <input
          :id="id"
          v-model="form.slug"
          class="input mono"
          maxlength="63"
          autocapitalize="off"
          spellcheck="false"
          :aria-invalid="invalid"
          :aria-describedby="describedBy"
          @input="slugTouched = true"
        />
      </FormField>
    </div>

    <FormField
      id="folder"
      label="Project folder"
      hint="Absolute path on the server. Steps run with this folder as working directory."
      :error="fieldErrors.folder ?? (folderLooksRelative ? 'Use an absolute path, e.g. /home/user/apps/shop.' : undefined)"
      v-slot="{ id, invalid, describedBy }"
    >
      <input
        :id="id"
        v-model="form.folder"
        class="input mono"
        placeholder="/home/user/apps/shop"
        autocapitalize="off"
        spellcheck="false"
        required
        :aria-invalid="invalid"
        :aria-describedby="describedBy"
      />
    </FormField>

    <FormField
      id="instructions"
      label="Instructions file"
      hint="Relative to the project folder (default deployer.yml) or an absolute path."
      :error="fieldErrors.instructions_file"
      v-slot="{ id, invalid, describedBy }"
    >
      <input
        :id="id"
        v-model="form.instructions_file"
        class="input mono"
        autocapitalize="off"
        spellcheck="false"
        :aria-invalid="invalid"
        :aria-describedby="describedBy"
      />
    </FormField>

    <FormField
      id="notifier"
      label="Notifier"
      optional
      :hint="notifiers.length ? 'Receives a message when a run finishes.' : 'No notifiers yet; add one under Notifiers.'"
      :error="fieldErrors.notifier_id"
      v-slot="{ id, describedBy }"
    >
      <select :id="id" v-model="form.notifier_id" class="select" :aria-describedby="describedBy">
        <option :value="null">None</option>
        <option v-for="notifier in notifiers" :key="notifier.id" :value="notifier.id">{{ notifier.name }}</option>
      </select>
    </FormField>

    <div class="form-actions">
      <button type="button" class="btn" @click="emit('cancel')">Cancel</button>
      <button type="submit" class="btn btn-primary" :disabled="busy || !form.name || !form.folder">
        {{ busy ? 'Saving…' : project ? 'Save changes' : 'Create project' }}
      </button>
    </div>
  </form>
</template>
