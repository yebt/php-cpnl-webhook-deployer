<script setup lang="ts">
import { computed } from 'vue'
import { actionLabel } from '../../lib/actions'
import AppIcon from '../ui/AppIcon.vue'

const props = defineProps<{ run: { action: string | null; action_name: string | null } }>()
const label = computed(() => actionLabel(props.run))
</script>

<template>
  <span v-if="label" class="action-badge" :title="`Action: ${run.action}`">
    <AppIcon name="bolt" :size="11" />
    <span class="truncate">{{ label }}</span>
  </span>
</template>

<style scoped>
.action-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  max-width: 180px;
  padding: 1px 7px;
  border: 1px solid color-mix(in srgb, var(--accent) 35%, transparent);
  border-radius: 999px;
  background: color-mix(in srgb, var(--accent) 10%, transparent);
  color: var(--accent);
  font-size: 11.5px;
  font-weight: 500;
  line-height: 17px;
  white-space: nowrap;
  vertical-align: middle;
}
.truncate { overflow: hidden; text-overflow: ellipsis; }
</style>
