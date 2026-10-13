import { onScopeDispose, readonly, ref, watchEffect } from 'vue'

export interface Crumb {
  label: string
  to?: string
}

const crumbs = ref<Crumb[]>([])

/** Views declare their breadcrumb trail; the top bar renders it. */
export function useBreadcrumbs(source: () => Crumb[]) {
  const stop = watchEffect(() => {
    crumbs.value = source()
  })
  onScopeDispose(stop)
}

export function useCurrentBreadcrumbs() {
  return readonly(crumbs)
}
