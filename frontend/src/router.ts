import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { appBasePath } from './lib/base'
import { useSession } from './session'

declare module 'vue-router' {
  interface RouteMeta {
    /** Reachable without a session (login, first-run setup). */
    public?: boolean
    /** Rendered without the sidebar shell. */
    bare?: boolean
  }
}

const routes: RouteRecordRaw[] = [
  { path: '/setup', name: 'setup', component: () => import('./views/SetupView.vue'), meta: { public: true, bare: true } },
  { path: '/login', name: 'login', component: () => import('./views/LoginView.vue'), meta: { public: true, bare: true } },
  { path: '/', name: 'overview', component: () => import('./views/OverviewView.vue') },
  { path: '/projects', name: 'projects', component: () => import('./views/ProjectsView.vue') },
  { path: '/projects/new', name: 'project-new', component: () => import('./views/ProjectEditView.vue') },
  { path: '/projects/:id(\\d+)', name: 'project', component: () => import('./views/ProjectDetailView.vue'), props: (r) => ({ id: Number(r.params.id) }) },
  { path: '/projects/:id(\\d+)/edit', name: 'project-edit', component: () => import('./views/ProjectEditView.vue'), props: (r) => ({ id: Number(r.params.id) }) },
  { path: '/projects/:id(\\d+)/instructions', name: 'project-instructions', component: () => import('./views/InstructionsView.vue'), props: (r) => ({ id: Number(r.params.id) }) },
  { path: '/runs', name: 'runs', component: () => import('./views/RunsView.vue') },
  { path: '/runs/:id(\\d+)', name: 'run', component: () => import('./views/RunView.vue'), props: (r) => ({ id: Number(r.params.id) }) },
  { path: '/notifiers', name: 'notifiers', component: () => import('./views/NotifiersView.vue') },
  { path: '/notifiers/new', name: 'notifier-new', component: () => import('./views/NotifierEditView.vue') },
  { path: '/notifiers/:id(\\d+)/edit', name: 'notifier-edit', component: () => import('./views/NotifierEditView.vue'), props: (r) => ({ id: Number(r.params.id) }) },
  { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('./views/NotFoundView.vue') },
]

export const router = createRouter({
  history: createWebHistory(appBasePath() + '/'),
  routes,
  scrollBehavior: (_to, _from, saved) => saved ?? { top: 0 },
})

router.beforeEach(async (to) => {
  const session = useSession()
  await session.resolve()

  if (session.needsSetup.value) {
    return to.name === 'setup' ? true : { name: 'setup' }
  }
  if (to.name === 'setup') return { name: session.user.value ? 'overview' : 'login' }
  if (!session.user.value && !to.meta.public) {
    return { name: 'login', query: to.fullPath === '/' ? {} : { redirect: to.fullPath } }
  }
  if (session.user.value && to.name === 'login') return { name: 'overview' }
  return true
})
