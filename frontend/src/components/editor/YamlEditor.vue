<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import type { EditorHandle } from './codemirror'

const model = defineModel<string>({ required: true })
const props = defineProps<{ label: string }>()
const emit = defineEmits<{ save: [] }>()

const host = ref<HTMLElement | null>(null)
const editor = shallowRef<EditorHandle | null>(null)
/** Plain textarea until CodeMirror loads, or for good if the chunk fails to load. */
const fallback = ref(true)

onMounted(async () => {
  try {
    const { createYamlEditor } = await import('./codemirror')
    if (!host.value) return
    editor.value = createYamlEditor(host.value, model.value, {
      label: props.label,
      onChange: (value) => (model.value = value),
      onSave: () => emit('save'),
    })
    fallback.value = false
  } catch {
    fallback.value = true
  }
})

watch(model, (value) => editor.value?.setValue(value))
onBeforeUnmount(() => editor.value?.destroy())

function onTextareaKey(event: KeyboardEvent) {
  if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
    event.preventDefault()
    emit('save')
  }
}
</script>

<template>
  <div class="editor">
    <textarea
      v-if="fallback"
      v-model="model"
      class="plain"
      :aria-label="label"
      spellcheck="false"
      autocapitalize="off"
      wrap="off"
      @keydown="onTextareaKey"
    />
    <div v-show="!fallback" ref="host" class="host" />
  </div>
</template>

<style scoped>
.editor {
  height: min(64vh, 720px);
  min-height: 320px;
  border-radius: var(--radius-sm);
  overflow: hidden;
  border: 1px solid var(--border);
  background: #0a0b0d;
}
.host { height: 100%; }
.plain {
  width: 100%;
  height: 100%;
  padding: 10px 12px;
  border: 0;
  resize: none;
  background: transparent;
  color: #d7dbe0;
  font-family: var(--mono);
  font-size: 13px;
  line-height: 1.6;
  tab-size: 2;
}
.plain:focus { outline: none; }
.editor:focus-within { border-color: var(--accent); }
</style>
