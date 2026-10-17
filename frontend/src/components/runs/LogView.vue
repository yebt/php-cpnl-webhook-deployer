<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import type { LogLine } from '../../api/types'
import { clockTime } from '../../lib/format'
import AppIcon from '../ui/AppIcon.vue'

const props = defineProps<{ lines: LogLine[]; live: boolean; focusStep: number | null }>()

const scroller = ref<HTMLElement | null>(null)
/** Follows new output until the user scrolls up; "Jump to latest" resumes. */
const follow = ref(true)
/** True once the log is scrolled: a top fade then separates the cut-off line from the card header. */
const scrolled = ref(false)

function atBottom(el: HTMLElement): boolean {
  return el.scrollHeight - el.scrollTop - el.clientHeight < 32
}

function onScroll() {
  if (!scroller.value) return
  follow.value = atBottom(scroller.value)
  scrolled.value = scroller.value.scrollTop > 0
}

function scrollToBottom() {
  const el = scroller.value
  if (!el) return
  el.scrollTop = el.scrollHeight
  follow.value = true
  scrolled.value = el.scrollTop > 0
}

watch(
  () => props.lines.length,
  async () => {
    if (!follow.value) return
    await nextTick()
    scrollToBottom()
  },
  { flush: 'post' },
)

// Selecting a step scrolls to its first line and pauses following.
watch(
  () => props.focusStep,
  async (position) => {
    if (position === null) return
    await nextTick()
    const target = scroller.value?.querySelector<HTMLElement>(`[data-step-start="${position}"]`)
    if (target && scroller.value) {
      follow.value = false
      scroller.value.scrollTop = target.offsetTop - 8
    }
  },
)

/** The runner logs the executed command as a system line starting with "$ ". */
function isCommand(line: LogLine): boolean {
  return line.stream === 'system' && line.line.startsWith('$ ')
}

function isStepHeader(line: LogLine): boolean {
  return line.stream === 'system' && /^Step \d+\/\d+: /.test(line.line)
}

function firstOfStep(index: number): number | undefined {
  const line = props.lines[index]
  if (!line || line.step === null) return undefined
  const previous = props.lines[index - 1]
  return previous?.step === line.step ? undefined : line.step
}
</script>

<template>
  <div class="log-wrap" :class="{ scrolled }">
    <div
      ref="scroller"
      class="log"
      role="log"
      aria-live="off"
      aria-label="Run log"
      tabindex="0"
      @scroll.passive="onScroll"
    >
      <p v-if="lines.length === 0" class="placeholder">{{ live ? 'Waiting for output…' : 'No output was recorded.' }}</p>
      <div
        v-for="(line, index) in lines"
        :key="line.id"
        class="line"
        :class="[`is-${line.stream}`, { command: isCommand(line), header: isStepHeader(line) }]"
        :data-step-start="firstOfStep(index)"
      >
        <time class="ts" :datetime="line.at">{{ clockTime(line.at) }}</time>
        <span v-if="isCommand(line)" class="text"><span class="prompt" aria-hidden="true">▸</span> {{ line.line.slice(2) }}</span>
        <span v-else class="text">{{ line.line }}</span>
      </div>
    </div>
    <button v-if="!follow && lines.length" type="button" class="btn btn-sm jump" @click="scrollToBottom">
      <AppIcon name="arrowDown" :size="14" />
      Jump to latest
    </button>
  </div>
</template>

<style scoped>
.log-wrap { position: relative; min-height: 0; display: flex; flex: 1; }
/* While scrolled, the topmost line fades out instead of looking clipped by the header. */
.log-wrap::before {
  content: '';
  position: absolute;
  inset: 0 0 auto;
  z-index: 1;
  height: var(--space-5);
  background: linear-gradient(var(--log-bg) 20%, transparent);
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.15s;
}
.log-wrap.scrolled::before { opacity: 1; }
.log {
  flex: 1;
  min-height: 320px;
  max-height: calc(100vh - 260px);
  overflow: auto;
  padding: var(--space-3) 0;
  background: var(--log-bg);
  border-radius: 0 0 var(--radius) var(--radius);
  color: #d7dbe0;
  font-family: var(--mono);
  font-size: 12.5px;
  line-height: 1.6;
}
.placeholder { margin: 0; padding: 0 var(--space-4); color: #8b929b; }
.line { display: flex; gap: var(--space-3); padding: 0 var(--space-4); }
.line:hover { background: rgba(255, 255, 255, 0.03); }
.ts { flex: none; color: #6b737d; user-select: none; }
.text { white-space: pre-wrap; overflow-wrap: anywhere; min-width: 0; }
.is-stderr .text { color: #ff9b8f; }
.is-system .text { color: #8b929b; }
.header { margin-top: var(--space-2); }
.header .text { color: #c9d1d9; font-weight: 600; }
.command .text { color: #7ee7d6; }
.prompt { color: #5eead4; }
.jump { position: absolute; right: var(--space-4); bottom: var(--space-3); box-shadow: 0 6px 18px rgba(0, 0, 0, 0.4); }
@media (max-width: 900px) {
  .log { max-height: 70vh; }
  .ts { display: none; }
}
</style>
