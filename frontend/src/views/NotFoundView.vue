<script setup lang="ts">
import EmptyState from '../components/ui/EmptyState.vue'
import { useBreadcrumbs } from '../composables/useBreadcrumbs'

useBreadcrumbs(() => [{ label: 'Not found' }])
</script>

<template>
  <div class="page">
    <EmptyState title="Page not found" text="The address does not match any page.">
      <RouterLink to="/" class="btn">Back to overview</RouterLink>
    </EmptyState>
  </div>
</template>
