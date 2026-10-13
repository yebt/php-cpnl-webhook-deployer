import { createApp } from 'vue'
import App from './App.vue'
import { onUnauthenticated } from './api'
import { router } from './router'
import { useSession } from './session'
import './styles/main.css'

// An expired session anywhere in the app sends the user to login, then back.
onUnauthenticated(() => {
  useSession().forget()
  const current = router.currentRoute.value
  if (current.matched.length > 0 && !current.meta.public) {
    void router.push({ name: 'login', query: { redirect: current.fullPath } })
  }
})

createApp(App).use(router).mount('#app')
